<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */


use Webman\Channel\Server;
use Workerman\Protocols\Frame;

return [
    'server' => [
        // 允许多个本地工作区并行联调，默认值保持 webman/channel 约定。
        'listen'  => env('CHANNEL_SERVER_LISTEN', 'frame://0.0.0.0:2206'),
        'protocol' => Frame::class,
        'handler' => Server::class,
        'reloadable' => false,
        'count' => 1, // 必须是1
    ]
];
