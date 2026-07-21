<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\process;

use plugin\saimulti\app\logic\tool\CrontabLogic;
use plugin\saimulti\app\model\tool\Crontab as CrontabModel;
use plugin\saimulti\service\module\ModuleServiceFactory;
use Webman\Channel\Client;
use Workerman\Crontab\Crontab;
use Workerman\Timer;
use Throwable;

class Task
{
    protected $logic; //login对象
    public $crontabIds = []; //定时任务表主键id => Crontab对象id

    private ?int $moduleExpiryTimerId = null;
    private ?int $uploadCleanupTimerId = null;

    public function __construct()
    {
        $dbName = env('DB_NAME');
        if (!empty($dbName)) {
            $this->logic = new CrontabLogic();
            // 连接webman channel服务
            Client::connect();
            // 订阅某个自定义事件并注册回调，收到事件后会自动触发此回调
            Client::on('crontab', function ($data) {
                $this->reload($data);
            });
        }
    }
    public function onWorkerStart()
    {
        $dbName = env('DB_NAME');
        if (!empty($dbName)) {
            $this->initStart();
            $this->scanExpiredModuleLicenses();
            $interval = (int) config('plugin.saimulti.module.expiry_scan_interval_seconds', 60);
            $this->moduleExpiryTimerId = Timer::add(
                max(10, $interval),
                fn () => $this->scanExpiredModuleLicenses(),
            );
            $this->scanUploadCleanup();
            $this->uploadCleanupTimerId = Timer::add(
                max(10, (int) config('plugin.saimulti.module.upload_cleanup_interval_seconds', 30)),
                fn () => $this->scanUploadCleanup(),
            );
        }
    }

    public function scanUploadCleanup(): void
    {
        try {
            $result = ModuleServiceFactory::uploadCleanup()->run(
                max(1, (int) config('plugin.saimulti.module.upload_cleanup_batch_size', 25)),
            );
            if ($result['scanned'] > 0 || $result['failed'] > 0) {
                echo PHP_EOL . date('Y-m-d H:i:s') . ' => 上传孤儿清理: '
                    . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            }
        } catch (Throwable $exception) {
            echo PHP_EOL . date('Y-m-d H:i:s') . ' => 上传孤儿清理失败: '
                . $exception->getMessage() . PHP_EOL;
        }
    }

    public function scanExpiredModuleLicenses(): void
    {
        try {
            $result = ModuleServiceFactory::expiryScanner()->run();
            if ($result['expired'] > 0 || $result['failed'] > 0) {
                echo PHP_EOL . date('Y-m-d H:i:s') . ' => 模块授权到期扫描: '
                    . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            }
        } catch (Throwable $exception) {
            echo PHP_EOL . date('Y-m-d H:i:s') . ' => 模块授权到期扫描失败: '
                . $exception->getMessage() . PHP_EOL;
        }
    }

    public function initStart()
    {
        $taskList = CrontabModel::where('status', 1)->select();
        foreach ($taskList as $item) {
            $crontab = new Crontab($item['rule'], function () use ($item) {
                $this->logic->run($item['id']);
            });
            $this->crontabIds[intval($item['id'])] = $crontab->getId(); //存储定时任务表主键id => Crontab对象id
            echo PHP_EOL . date('Y-m-d H:i:s') . " => 定时任务[" . $item['id'] . "][" . $item['name'] . "]:启动成功" . PHP_EOL;
        }
    }

    public function reload($data)
    {
        $id = intval($data['args'] ?? 0); //定时任务表主键id
        if (isset($this->crontabIds[$id])) {
            Crontab::remove($this->crontabIds[$id]);
            unset($this->crontabIds[$id]); //删除定时任务表主键id => Crontab对象id
            echo PHP_EOL . date('Y-m-d H:i:s') . " => 定时任务[" . $id . "]:移除成功" . PHP_EOL;
        }
        $item = $this->logic->read($id);// 查询定时任务表数据
        if ($item && $item['status'] == 1) {
            $crontab = new Crontab($item['rule'], function () use ($item) {
                $this->logic->run($item['id']);
            });
            $this->crontabIds[$id] = $crontab->getId(); //存储定时任务表主键id => Crontab对象id
            echo PHP_EOL . date('Y-m-d H:i:s') . " => 定时任务[" . $item['id'] . "][" . $item['name'] . "]:启动成功" . PHP_EOL;
        }
    }

}
