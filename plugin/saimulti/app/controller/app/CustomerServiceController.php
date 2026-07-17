<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\app;

use plugin\saimulti\service\ModuleRequired;

#[ModuleRequired('customer_service', 'server', 'customer_service.app.use')]
final class CustomerServiceController extends \plugin\saimulti\app\controller\web\CustomerServiceController
{
}
