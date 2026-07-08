<?php
return [
    'admin.login' => [
        [plugin\saimulti\app\event\SystemAdmin::class, 'login'],
    ],
    'admin.operateLog' => [
        [plugin\saimulti\app\event\SystemAdmin::class, 'operateLog'],
    ],
    'tenant.login' => [
        [plugin\saimulti\app\event\SystemTenant::class, 'login'],
    ],
    'tenant.operateLog' => [
        [plugin\saimulti\app\event\SystemTenant::class, 'operateLog'],
    ]
];