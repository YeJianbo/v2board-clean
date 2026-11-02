package main

import (
	"net"
	"testing"
)

func TestPublicUpstreamIP(t *testing.T) {
	blocked := []string{
		"0.1.2.3", "10.0.0.1", "127.0.0.1", "169.254.169.254", "172.16.0.1", "192.168.0.1",
		"100.64.0.0", "100.100.100.200", "100.127.255.255",
		"198.18.0.0", "198.19.255.255", "240.0.0.1", "255.255.255.255", "224.0.0.1",
		"192.0.0.8", "192.0.0.170", "192.0.2.1", "198.51.100.1", "203.0.113.1",
		"::", "::1", "::192.168.1.1", "fc00::1", "fe80::1", "ff02::1",
		"::ffff:100.100.100.200", "::ffff:198.18.0.1", "::ffff:127.0.0.1",
		"64:ff9b::a00:1", "64:ff9b::7f00:1", "64:ff9b::a9fe:a9fe",
		"64:ff9b::6440:0", "64:ff9b::6464:64c8", "64:ff9b::647f:ffff",
		"64:ff9b::c612:0", "64:ff9b::c613:ffff", "64:ff9b::c000:201", "64:ff9b::f000:1",
		"64:ff9b:1::808:808", "64:ff9b:1:ffff:ffff:ffff:ffff:ffff",
		"100::1", "2001:2::1", "2001:db8::1", "3fff::1",
	}
	for _, raw := range blocked {
		t.Run("reject_"+raw, func(t *testing.T) {
			if publicUpstreamIP(net.ParseIP(raw)) {
				t.Fatalf("non-public address accepted: %s", raw)
			}
		})
	}
	allowed := []string{
		"8.8.8.8", "1.1.1.1", "100.63.255.255", "100.128.0.0", "198.17.255.255", "198.20.0.0",
		"192.0.0.9", "192.0.0.10", "192.0.1.1", "23.141.12.117",
		"::ffff:8.8.8.8", "64:ff9b::808:808", "64:ff9b::c000:9",
		"64:ff9b::643f:ffff", "64:ff9b::6480:0", "64:ff9b::c611:ffff", "64:ff9b::c614:0",
		"2001:4860:4860::8888", "2606:4700:4700::1111", "2400:c620:2f:74::a",
	}
	for _, raw := range allowed {
		t.Run("allow_"+raw, func(t *testing.T) {
			if !publicUpstreamIP(net.ParseIP(raw)) {
				t.Fatalf("public address rejected: %s", raw)
			}
		})
	}
	if publicUpstreamIP(nil) {
		t.Fatal("invalid address accepted")
	}
}

func TestValidateUpstreamAddresses(t *testing.T) {
	public := net.IPAddr{IP: net.ParseIP("8.8.8.8")}
	blocked := net.IPAddr{IP: net.ParseIP("64:ff9b::6464:64c8")}
	for _, ips := range [][]net.IPAddr{nil, {public, blocked}, {blocked, public}, {{IP: nil}}, {{IP: public.IP, Zone: "eth0"}}} {
		if err := validateUpstreamAddresses(ips); err == nil {
			t.Fatalf("invalid DNS answer set accepted: %v", ips)
		}
	}
	if err := validateUpstreamAddresses([]net.IPAddr{public, {IP: net.ParseIP("2606:4700:4700::1111")}}); err != nil {
		t.Fatal(err)
	}
}
