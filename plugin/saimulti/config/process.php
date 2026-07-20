<?php
return [
    'task'  => [
        'handler'  => plugin\saimulti\process\Task::class
    ],
    'search-consumer' => [
        'handler' => plugin\saimulti\process\SearchConsumerProcess::class,
        'count' => 1,
    ],
];
