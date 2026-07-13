<?php

use plugin\saimulti\app\middleware\CrossDomain;
use plugin\saimulti\app\middleware\HttpTrace;

return [
    '' => [
        HttpTrace::class,
        CrossDomain::class,
    ]
];
