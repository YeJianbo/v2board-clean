<?php

return [
    'installer_url' => env(
        'RAVEL_PROBE_INSTALLER_URL',
        'https://raw.githubusercontent.com/YeJianbo/v2node/main/script/install.sh'
    ),
    'update_repo' => env('RAVEL_PROBE_UPDATE_REPO', 'YeJianbo/v2node'),
];
