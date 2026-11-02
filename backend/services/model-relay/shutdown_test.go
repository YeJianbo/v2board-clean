package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/gorilla/websocket"
)

func TestShutdownWaitsForHijackedWebSocketSettlement(t *testing.T) {
	started, settling, finish := make(chan struct{}), make(chan struct{}), make(chan struct{})
	var releaseOnce sync.Once
	release := func() { releaseOnce.Do(func() { close(finish) }) }
	up := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		upgrader := websocket.Upgrader{}
		conn, err := upgrader.Upgrade(w, r, nil)
		if err != nil {
			return
		}
		defer conn.Close()
		if _, _, err := conn.ReadMessage(); err != nil {
			return
		}
		close(started)
		_, _, _ = conn.ReadMessage()
	}))
	defer up.Close()
	panel := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/settle") {
			close(settling)
			select {
			case <-finish:
			case <-r.Context().Done():
				return
			}
			_, _ = w.Write([]byte(`{}`))
			return
		}
		_ = json.NewEncoder(w).Encode(reservation{URL: up.URL, Key: "test-upstream", Max: 30})
	}))
	defer panel.Close()
	ctx, cancel := context.WithCancel(context.Background())
	g := &gateway{context: ctx, panel: panel.URL, callbacks: &http.Client{Timeout: 5 * time.Second},
		spool: t.TempDir(), slots: make(chan struct{}, 2), wsDial: websocket.DefaultDialer.DialContext}
	server := httptest.NewServer(g)
	defer server.Close()
	defer release()
	defer cancel()
	conn, _, err := websocket.DefaultDialer.Dial("ws"+strings.TrimPrefix(server.URL, "http")+"/v1/responses",
		http.Header{"Authorization": []string{"Bearer bc-test"}})
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	if err := conn.WriteJSON(map[string]any{"type": "response.create", "model": "test", "input": "hello"}); err != nil {
		t.Fatal(err)
	}
	select {
	case <-started:
	case <-time.After(5 * time.Second):
		t.Fatal("upstream turn was not started")
	}
	drained := g.stopRequests()
	cancel()
	select {
	case <-settling:
	case <-time.After(5 * time.Second):
		t.Fatal("cancelled turn was not settled")
	}
	shutdown, done := context.WithTimeout(context.Background(), time.Second)
	defer done()
	if err := server.Config.Shutdown(shutdown); err != nil {
		t.Fatal(err)
	}
	select {
	case <-drained:
		t.Fatal("gateway drained before the hijacked request settled")
	default:
	}
	response := httptest.NewRecorder()
	g.ServeHTTP(response, httptest.NewRequest("GET", "/health", nil))
	if response.Code != 503 {
		t.Fatalf("new request admitted during shutdown: %d", response.Code)
	}
	release()
	select {
	case <-drained:
	case <-time.After(5 * time.Second):
		t.Fatal("settled request did not drain")
	}
}
