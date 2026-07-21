<?php
return [
    'task'  => [
        'handler'  => plugin\saimulti\process\Task::class
    ],
    'search-consumer' => [
        'handler' => plugin\saimulti\process\SearchConsumerProcess::class,
        'count' => 1,
    ],
    'search-rebuild' => [
        'handler' => plugin\saimulti\process\SearchRebuildProcess::class,
        'count' => 1,
    ],
];
