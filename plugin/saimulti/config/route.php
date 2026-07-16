<?php

use plugin\saimulti\app\middleware\AdminLog;
use plugin\saimulti\app\middleware\CheckAdminAuth;
use plugin\saimulti\app\middleware\CheckAdminLogin;
use plugin\saimulti\app\middleware\CheckTenantAuth;
use plugin\saimulti\app\middleware\CheckTenantLogin;
use plugin\saimulti\app\middleware\CheckWebLogin;
use plugin\saimulti\app\middleware\CrossDomain;
use plugin\saimulti\app\middleware\TenantLog;
use plugin\saimulti\app\middleware\PublicGuestCors;
use plugin\saimulti\app\middleware\WebCors;
use Webman\Route;

// 验证码
Route::get("/saimulti/captcha", [plugin\saimulti\app\controller\LoginController::class, 'captcha']);
// 管理中台登录
Route::post('/saimulti/admin/login', [plugin\saimulti\app\controller\LoginController::class, 'adminLogin']);
// 租户端登录
Route::post('/saimulti/tenant/login', [plugin\saimulti\app\controller\LoginController::class, 'tenantLogin']);
// 应用信息
Route::get("/saimulti/appInfo", [plugin\saimulti\app\controller\LoginController::class, 'appInfo']);
Route::options("/saimulti/appInfo", [plugin\saimulti\app\controller\LoginController::class, 'appInfoOptions']);
// 客服公开接口（无需登录 / 无需 App-Id；organization 由入口/guest token 决定）
Route::group('/saimulti/public/customer-service', function () {
	$c = \plugin\saimulti\app\controller\publicapi\CustomerServicePublicController::class;
	Route::get('/entry/resolve', [$c, 'resolveEntry']);
	Route::post('/session/create', [$c, 'sessionCreate']);
	Route::post('/session/close', [$c, 'sessionClose']);
	Route::get('/session/me', [$c, 'sessionMe']);
	Route::get('/conversation/index', [$c, 'conversationIndex']);
	Route::get('/conversation/read', [$c, 'conversationRead']);
	Route::post('/conversation/save', [$c, 'conversationSave']);
	foreach ([
		'/entry/resolve',
		'/session/create',
		'/session/close',
		'/session/me',
		'/conversation/index',
		'/conversation/read',
		'/conversation/save',
	] as $path) {
		Route::options($path, static fn () => response('', 204));
	}
})->middleware([
	PublicGuestCors::class,
]);

// Web 普通用户公开登录。App-Id 和 Origin 由 WebCors/OrganizationResolver 校验。
Route::group('/saimulti', function () {
	Route::post('/web/im/login', [\plugin\saimulti\app\controller\web\ImController::class, 'login']);
	Route::options('/web/im/login', static fn () => response('', 204));
})->middleware([
	WebCors::class,
]);

// Web 普通用户认证接口。organization 只从 Web access token 取得。
Route::group('/saimulti', function () {
	Route::post('/web/im/imToken', [\plugin\saimulti\app\controller\web\ImController::class, 'imToken']);
	Route::get('/web/im/me', [\plugin\saimulti\app\controller\web\ImController::class, 'me']);
	Route::post('/web/im/updateAvatar', [\plugin\saimulti\app\controller\web\ImController::class, 'updateAvatar']);
	Route::get('/web/im/conversations', [\plugin\saimulti\app\controller\web\ImController::class, 'conversations']);
	Route::get('/web/im/messageGroups', [\plugin\saimulti\app\controller\web\ImController::class, 'messageGroups']);
	Route::post('/web/im/createMessageGroup', [\plugin\saimulti\app\controller\web\ImController::class, 'createMessageGroup']);
	Route::post('/web/im/updateConversationGroup', [\plugin\saimulti\app\controller\web\ImController::class, 'updateConversationGroup']);
	Route::get('/web/im/messageConfig', [\plugin\saimulti\app\controller\web\ImController::class, 'messageConfig']);
	Route::get('/web/im/messages', [\plugin\saimulti\app\controller\web\ImController::class, 'messages']);
	Route::post('/web/im/markRead', [\plugin\saimulti\app\controller\web\ImController::class, 'markRead']);
	Route::get('/web/im/contacts', [\plugin\saimulti\app\controller\web\ImController::class, 'contacts']);
	Route::get('/web/im/searchUsers', [\plugin\saimulti\app\controller\web\ImController::class, 'searchUsers']);
	Route::get('/web/im/requests', [\plugin\saimulti\app\controller\web\ImController::class, 'requests']);
	Route::post('/web/im/sendFriendRequest', [\plugin\saimulti\app\controller\web\ImController::class, 'sendFriendRequest']);
	Route::post('/web/im/handleFriendRequest', [\plugin\saimulti\app\controller\web\ImController::class, 'handleFriendRequest']);
	Route::post('/web/im/createGroup', [\plugin\saimulti\app\controller\web\ImController::class, 'createGroup']);
	Route::get('/web/im/groupMembers', [\plugin\saimulti\app\controller\web\ImController::class, 'groupMembers']);
	Route::post('/web/im/addGroupMembers', [\plugin\saimulti\app\controller\web\ImController::class, 'addGroupMembers']);
	Route::post('/web/im/updateConversationSetting', [\plugin\saimulti\app\controller\web\ImController::class, 'updateConversationSetting']);
	Route::post('/web/im/updateGroupProfile', [\plugin\saimulti\app\controller\web\ImController::class, 'updateGroupProfile']);
	Route::post('/web/im/updateGroupManagers', [\plugin\saimulti\app\controller\web\ImController::class, 'updateGroupManagers']);
	Route::post('/web/im/updateGroupMemberStatus', [\plugin\saimulti\app\controller\web\ImController::class, 'updateGroupMemberStatus']);
	Route::post('/web/im/removeGroupMember', [\plugin\saimulti\app\controller\web\ImController::class, 'removeGroupMember']);
	Route::post('/web/im/updateFriendRemark', [\plugin\saimulti\app\controller\web\ImController::class, 'updateFriendRemark']);
	Route::get('/web/im/searchMessages', [\plugin\saimulti\app\controller\web\ImController::class, 'searchMessages']);
	Route::post('/web/im/prepareUpload', [\plugin\saimulti\app\controller\web\ImController::class, 'prepareUpload']);
	Route::post('/web/im/upload', [\plugin\saimulti\app\controller\web\ImController::class, 'upload']);
	Route::post('/web/im/confirmUpload', [\plugin\saimulti\app\controller\web\ImController::class, 'confirmUpload']);
	Route::post('/web/im/deriveForwardAsset', [\plugin\saimulti\app\controller\web\ImController::class, 'deriveForwardAsset']);
	Route::post('/web/im/resolveAssetUrl', [\plugin\saimulti\app\controller\web\ImController::class, 'resolveAssetUrl']);
	Route::get('/client/config', [\plugin\saimulti\app\controller\web\ClientConfigController::class, 'index']);
	Route::get('/web/announcement/index', [\plugin\saimulti\app\controller\web\AnnouncementController::class, 'index']);
	Route::get('/web/announcement/read', [\plugin\saimulti\app\controller\web\AnnouncementController::class, 'read']);
	Route::post('/web/announcement/acknowledge', [\plugin\saimulti\app\controller\web\AnnouncementController::class, 'acknowledge']);
	Route::get('/web/i18n/locales', [\plugin\saimulti\app\controller\web\I18nController::class, 'locales']);
	Route::get('/web/i18n/messages', [\plugin\saimulti\app\controller\web\I18nController::class, 'messages']);
	Route::get('/web/favorite/index', [\plugin\saimulti\app\controller\web\FavoriteController::class, 'index']);
	Route::get('/web/favorite/read', [\plugin\saimulti\app\controller\web\FavoriteController::class, 'read']);
	Route::post('/web/favorite/save', [\plugin\saimulti\app\controller\web\FavoriteController::class, 'save']);
	// Web 客户端 requestWebApi 仅支持 GET/POST
	Route::post('/web/favorite/destroy', [\plugin\saimulti\app\controller\web\FavoriteController::class, 'destroy']);
	Route::delete('/web/favorite/destroy', [\plugin\saimulti\app\controller\web\FavoriteController::class, 'destroy']);
	Route::get('/web/sticker/packs', [\plugin\saimulti\app\controller\web\StickerController::class, 'packs']);
	Route::get('/web/sticker/items', [\plugin\saimulti\app\controller\web\StickerController::class, 'items']);
	Route::get('/web/customer-service/conversation/index', [\plugin\saimulti\app\controller\web\CustomerServiceController::class, 'conversationIndex']);
	Route::get('/web/customer-service/conversation/read', [\plugin\saimulti\app\controller\web\CustomerServiceController::class, 'conversationRead']);
	Route::post('/web/customer-service/conversation/save', [\plugin\saimulti\app\controller\web\CustomerServiceController::class, 'conversationSave']);
	Route::get('/web/robot-single/index', [\plugin\saimulti\app\controller\web\RobotSingleController::class, 'index']);
	Route::get('/web/robot-single/read', [\plugin\saimulti\app\controller\web\RobotSingleController::class, 'read']);
	Route::post('/web/robot-single/match', [\plugin\saimulti\app\controller\web\RobotSingleController::class, 'match']);
	Route::get('/web/file-media/usage', [\plugin\saimulti\app\controller\web\FileMediaController::class, 'usage']);
	Route::post('/web/file-media/checkUpload', [\plugin\saimulti\app\controller\web\FileMediaController::class, 'checkUpload']);
	Route::get('/web/file-media/folderIndex', [\plugin\saimulti\app\controller\web\FileMediaController::class, 'folderIndex']);
	Route::post('/web/file-media/folderSave', [\plugin\saimulti\app\controller\web\FileMediaController::class, 'folderSave']);
	Route::get('/web/file-media/itemIndex', [\plugin\saimulti\app\controller\web\FileMediaController::class, 'itemIndex']);
	Route::post('/web/file-media/itemSave', [\plugin\saimulti\app\controller\web\FileMediaController::class, 'itemSave']);
	Route::post('/web/file-media/itemDestroy', [\plugin\saimulti\app\controller\web\FileMediaController::class, 'itemDestroy']);
	Route::get('/web/search/messages', [\plugin\saimulti\app\controller\web\SearchController::class, 'messages']);
	Route::get('/web/search/indexStatus', [\plugin\saimulti\app\controller\web\SearchController::class, 'indexStatus']);
	Route::get('/web/moments/feed', [\plugin\saimulti\app\controller\web\MomentsController::class, 'feed']);
	Route::get('/web/moments/read', [\plugin\saimulti\app\controller\web\MomentsController::class, 'read']);
	Route::post('/web/moments/save', [\plugin\saimulti\app\controller\web\MomentsController::class, 'save']);
	Route::post('/web/moments/destroy', [\plugin\saimulti\app\controller\web\MomentsController::class, 'destroy']);
	Route::get('/web/moments/commentIndex', [\plugin\saimulti\app\controller\web\MomentsController::class, 'commentIndex']);
	Route::post('/web/moments/commentSave', [\plugin\saimulti\app\controller\web\MomentsController::class, 'commentSave']);
	Route::post('/web/moments/likeToggle', [\plugin\saimulti\app\controller\web\MomentsController::class, 'likeToggle']);
	Route::get('/web/moments/profileRead', [\plugin\saimulti\app\controller\web\MomentsController::class, 'profileRead']);
	Route::post('/web/moments/profileUpdate', [\plugin\saimulti\app\controller\web\MomentsController::class, 'profileUpdate']);

	foreach ([
		'/web/im/imToken',
		'/web/im/me',
		'/web/im/updateAvatar',
		'/web/im/conversations',
		'/web/im/messageGroups',
		'/web/im/createMessageGroup',
		'/web/im/updateConversationGroup',
		'/web/im/messageConfig',
		'/web/im/messages',
		'/web/im/markRead',
		'/web/im/contacts',
		'/web/im/searchUsers',
		'/web/im/requests',
		'/web/im/sendFriendRequest',
		'/web/im/handleFriendRequest',
		'/web/im/createGroup',
		'/web/im/groupMembers',
		'/web/im/addGroupMembers',
		'/web/im/updateConversationSetting',
		'/web/im/updateGroupProfile',
		'/web/im/updateGroupManagers',
		'/web/im/updateGroupMemberStatus',
		'/web/im/removeGroupMember',
		'/web/im/updateFriendRemark',
		'/web/im/searchMessages',
		'/web/im/prepareUpload',
		'/web/im/upload',
		'/web/im/confirmUpload',
		'/web/im/deriveForwardAsset',
		'/web/im/resolveAssetUrl',
		'/client/config',
		'/web/announcement/index',
		'/web/announcement/read',
		'/web/announcement/acknowledge',
		'/web/i18n/locales',
		'/web/i18n/messages',
		'/web/favorite/index',
		'/web/favorite/read',
		'/web/favorite/save',
		'/web/favorite/destroy',
		'/web/sticker/packs',
		'/web/sticker/items',
		'/web/customer-service/conversation/index',
		'/web/customer-service/conversation/read',
		'/web/customer-service/conversation/save',
		'/web/robot-single/index',
		'/web/robot-single/read',
		'/web/robot-single/match',
		'/web/file-media/usage',
		'/web/file-media/checkUpload',
		'/web/file-media/folderIndex',
		'/web/file-media/folderSave',
		'/web/file-media/itemIndex',
		'/web/file-media/itemSave',
		'/web/file-media/itemDestroy',
		'/web/search/messages',
		'/web/search/indexStatus',
		'/web/moments/feed',
		'/web/moments/read',
		'/web/moments/save',
		'/web/moments/destroy',
		'/web/moments/commentIndex',
		'/web/moments/commentSave',
		'/web/moments/likeToggle',
		'/web/moments/profileRead',
		'/web/moments/profileUpdate',
	] as $path) {
		Route::options($path, static fn () => response('', 204));
	}
})->middleware([
	WebCors::class,
	CheckWebLogin::class,
]);

Route::group("/saimulti", function () {
	// 管理中台信息
	Route::get('/admin/user', [\plugin\saimulti\app\controller\system\AdminsController::class, 'userInfo']);
	Route::get('/admin/menu', [\plugin\saimulti\app\controller\system\AdminsController::class, 'menu']);
	Route::get('/admin/clearAllCache', [\plugin\saimulti\app\controller\AdminCommonController::class, 'clearAllCache']);
	Route::post("/admin/updateInfo", [\plugin\saimulti\app\controller\system\AdminsController::class, 'updateInfo']);
	Route::post("/admin/modifyPassword", [\plugin\saimulti\app\controller\system\AdminsController::class, 'modifyPassword']);
	Route::get('/admin/loginList', [\plugin\saimulti\app\controller\system\AdminsController::class, 'loginList']);
	Route::get('/admin/operList', [\plugin\saimulti\app\controller\system\AdminsController::class, 'operList']);

	// 常规接口
	Route::get("/system/dictAll", [plugin\saimulti\app\controller\AdminCommonController::class, 'dictAll']);
	Route::get("/system/getResourceCategory", [plugin\saimulti\app\controller\AdminCommonController::class, 'getResourceCategory']);
	Route::get("/system/getResourceList", [plugin\saimulti\app\controller\AdminCommonController::class, 'getResourceList']);
	Route::get("/system/areaCode", [plugin\saimulti\app\controller\AdminCommonController::class, 'areaCode']);
	Route::post("/system/uploadImage", [plugin\saimulti\app\controller\AdminCommonController::class, 'uploadImage']);
	Route::post("/system/uploadFile", [plugin\saimulti\app\controller\AdminCommonController::class, 'uploadFile']);

	//--------------------------- 系统管理 ------------------------- //
	// 菜单管理
	saiMultiRoute('/system/coreMenu', \plugin\saimulti\app\controller\system\MenuController::class);
	Route::get('/system/coreMenu/accessMenu', [\plugin\saimulti\app\controller\system\MenuController::class, 'accessMenu']);

	// 角色管理
	saiMultiRoute("/system/coreRole", \plugin\saimulti\app\controller\system\RoleController::class);
	Route::get("/system/coreRole/accessRole", [\plugin\saimulti\app\controller\system\RoleController::class, 'accessRole']);
	Route::get("/system/coreRole/getMenuByRole", [\plugin\saimulti\app\controller\system\RoleController::class, 'getMenuByRole']);
	Route::post("/system/coreRole/menuPermission", [\plugin\saimulti\app\controller\system\RoleController::class, 'menuPermission']);

	// 部门管理
	saiMultiRoute("/system/coreDept", \plugin\saimulti\app\controller\system\DeptController::class);
	Route::get("/system/coreDept/accessDept", [\plugin\saimulti\app\controller\system\DeptController::class, 'accessDept']);

	// 账号管理
	saiMultiRoute("/system/admin", \plugin\saimulti\app\controller\system\AdminsController::class);
	Route::post("/system/admin/setHomePage", [\plugin\saimulti\app\controller\system\AdminsController::class, 'setHomePage']);
	Route::post("/system/admin/clearCache", [\plugin\saimulti\app\controller\system\AdminsController::class, 'clearCache']);
	Route::post("/system/admin/reset", [\plugin\saimulti\app\controller\system\AdminsController::class, 'initUserPassword']);

	// 数据字典
	saiMultiRoute('/system/dictType', \plugin\saimulti\app\controller\system\SystemDictTypeController::class);
	saiMultiRoute('/system/dictData', \plugin\saimulti\app\controller\system\SystemDictDataController::class);

	// 系统设置
	saiMultiRoute('/system/configGroup', \plugin\saimulti\app\controller\system\SystemConfigGroupController::class);
	Route::post("/system/configGroup/email", [\plugin\saimulti\app\controller\system\SystemConfigGroupController::class, 'email']);
	saiMultiRoute('/system/config', \plugin\saimulti\app\controller\system\SystemConfigController::class);
	Route::post("/system/config/batchUpdate", [\plugin\saimulti\app\controller\system\SystemConfigController::class, 'batchUpdate']);

	// 全链路查询（控制器内额外限制为平台超级管理员）
	Route::get('/system/trace/services', [\plugin\saimulti\app\controller\system\TraceController::class, 'services']);
	Route::get('/system/trace/search', [\plugin\saimulti\app\controller\system\TraceController::class, 'search']);
	Route::get('/system/trace/read', [\plugin\saimulti\app\controller\system\TraceController::class, 'read']);

	//--------------------------- 租户管理 ------------------------- //
	// 机构管理
	saiMultiRoute('/admin/organization', \plugin\saimulti\app\controller\admin\SystemOrganizationController::class);
	Route::post("/admin/organization/initTenant", [\plugin\saimulti\app\controller\admin\SystemOrganizationController::class, 'initTenant']);
	Route::get('/admin/routing/read', [\plugin\saimulti\app\controller\admin\AdminRoutingController::class, 'read']);
	Route::post('/admin/routing/publish', [\plugin\saimulti\app\controller\admin\AdminRoutingController::class, 'publish']);

	// 机构分组
	saiMultiRoute('/admin/group', \plugin\saimulti\app\controller\admin\SystemGroupController::class);
	Route::get("/admin/group/getMenuByGroup", [\plugin\saimulti\app\controller\admin\SystemGroupController::class, 'getMenuByGroup']);
	Route::post("/admin/group/updateMenuGroup", [\plugin\saimulti\app\controller\admin\SystemGroupController::class, 'updateMenuGroup']);

	// 机构账号
	saiMultiRoute('/admin/user', \plugin\saimulti\app\controller\admin\SystemUserController::class);
	Route::post("/admin/user/clearCache", [\plugin\saimulti\app\controller\admin\SystemUserController::class, 'clearCache']);
	Route::post("/admin/user/reset", [\plugin\saimulti\app\controller\admin\SystemUserController::class, 'initUserPassword']);

	// 菜单管理
	saiMultiRoute('/admin/menu', \plugin\saimulti\app\controller\admin\SystemMenuController::class);

	// 统一模块生命周期与租户授权
	Route::get('/admin/module/catalog', [\plugin\saimulti\app\controller\admin\ModuleController::class, 'catalog']);
	Route::get('/admin/module/read', [\plugin\saimulti\app\controller\admin\ModuleController::class, 'read']);
	Route::post('/admin/module/discover', [\plugin\saimulti\app\controller\admin\ModuleController::class, 'discover']);
	Route::post('/admin/module/install', [\plugin\saimulti\app\controller\admin\ModuleController::class, 'install']);
	Route::post('/admin/module/upgrade', [\plugin\saimulti\app\controller\admin\ModuleController::class, 'upgrade']);
	Route::post('/admin/module/enable', [\plugin\saimulti\app\controller\admin\ModuleController::class, 'enable']);
	Route::post('/admin/module/disable', [\plugin\saimulti\app\controller\admin\ModuleController::class, 'disable']);
	Route::post('/admin/module/uninstall', [\plugin\saimulti\app\controller\admin\ModuleController::class, 'uninstall']);
	Route::post('/admin/module/license/grant', [\plugin\saimulti\app\controller\admin\ModuleController::class, 'grant']);
	Route::post('/admin/module/license/revoke', [\plugin\saimulti\app\controller\admin\ModuleController::class, 'revoke']);

	// 内置公告模块（权限、系统启用状态由现有鉴权中间件统一校验）
	saiMultiRoute('/admin/announcement', \plugin\saimulti\app\controller\admin\AnnouncementController::class);

	// 商业模块 i18n（平台语言与词条）
	saiMultiRoute('/admin/i18n', \plugin\saimulti\app\controller\admin\I18nController::class);
	Route::get('/admin/i18n/entryIndex', [\plugin\saimulti\app\controller\admin\I18nController::class, 'entryIndex']);
	Route::post('/admin/i18n/entrySave', [\plugin\saimulti\app\controller\admin\I18nController::class, 'entrySave']);
	Route::put('/admin/i18n/entryUpdate', [\plugin\saimulti\app\controller\admin\I18nController::class, 'entryUpdate']);
	Route::delete('/admin/i18n/entryDestroy', [\plugin\saimulti\app\controller\admin\I18nController::class, 'entryDestroy']);

	// 商业模块 favorite
	saiMultiRoute('/admin/favorite', \plugin\saimulti\app\controller\admin\FavoriteController::class);

	// 商业模块 sticker
	saiMultiRoute('/admin/sticker', \plugin\saimulti\app\controller\admin\StickerController::class);
	Route::get('/admin/sticker/itemIndex', [\plugin\saimulti\app\controller\admin\StickerController::class, 'itemIndex']);
	Route::post('/admin/sticker/itemSave', [\plugin\saimulti\app\controller\admin\StickerController::class, 'itemSave']);
	Route::put('/admin/sticker/itemUpdate', [\plugin\saimulti\app\controller\admin\StickerController::class, 'itemUpdate']);
	Route::delete('/admin/sticker/itemDestroy', [\plugin\saimulti\app\controller\admin\StickerController::class, 'itemDestroy']);

	// 商业模块 customer_service（平台）
	Route::get('/admin/customer-service/conversation/index', [\plugin\saimulti\app\controller\admin\CustomerServiceController::class, 'conversationIndex']);
	Route::get('/admin/customer-service/conversation/read', [\plugin\saimulti\app\controller\admin\CustomerServiceController::class, 'conversationRead']);

	// 商业模块 robot_single（平台只读）
	Route::get('/admin/robot-single/index', [\plugin\saimulti\app\controller\admin\RobotSingleController::class, 'index']);
	Route::get('/admin/robot-single/read', [\plugin\saimulti\app\controller\admin\RobotSingleController::class, 'read']);
	Route::get('/admin/robot-single/ruleIndex', [\plugin\saimulti\app\controller\admin\RobotSingleController::class, 'ruleIndex']);
	Route::get('/admin/robot-single/kbIndex', [\plugin\saimulti\app\controller\admin\RobotSingleController::class, 'kbIndex']);

	// 商业模块 file_media（平台）
	Route::get('/admin/file-media/quotaIndex', [\plugin\saimulti\app\controller\admin\FileMediaController::class, 'quotaIndex']);
	Route::get('/admin/file-media/quotaRead', [\plugin\saimulti\app\controller\admin\FileMediaController::class, 'quotaRead']);
	Route::put('/admin/file-media/quotaUpdate', [\plugin\saimulti\app\controller\admin\FileMediaController::class, 'quotaUpdate']);
	Route::get('/admin/file-media/itemIndex', [\plugin\saimulti\app\controller\admin\FileMediaController::class, 'itemIndex']);
	Route::get('/admin/file-media/folderIndex', [\plugin\saimulti\app\controller\admin\FileMediaController::class, 'folderIndex']);

	// 商业模块 search（平台）
	Route::get('/admin/search/indexList', [\plugin\saimulti\app\controller\admin\SearchController::class, 'indexList']);
	Route::get('/admin/search/indexRead', [\plugin\saimulti\app\controller\admin\SearchController::class, 'indexRead']);
	Route::post('/admin/search/rebuild', [\plugin\saimulti\app\controller\admin\SearchController::class, 'rebuild']);
	Route::get('/admin/search/jobIndex', [\plugin\saimulti\app\controller\admin\SearchController::class, 'jobIndex']);
	Route::post('/admin/search/docUpsert', [\plugin\saimulti\app\controller\admin\SearchController::class, 'docUpsert']);

	// 商业模块 moments（平台）
	Route::get('/admin/moments/index', [\plugin\saimulti\app\controller\admin\MomentsController::class, 'index']);
	Route::get('/admin/moments/read', [\plugin\saimulti\app\controller\admin\MomentsController::class, 'read']);
	Route::delete('/admin/moments/destroy', [\plugin\saimulti\app\controller\admin\MomentsController::class, 'destroy']);

	// IM 运行管理与安全审计
	Route::get('/admin/im/operations/overview', [\plugin\saimulti\app\controller\admin\AdminImOperationsController::class, 'overview']);
	Route::get('/admin/im/operations/users', [\plugin\saimulti\app\controller\admin\AdminImOperationsController::class, 'users']);
	Route::get('/admin/im/operations/devices', [\plugin\saimulti\app\controller\admin\AdminImOperationsController::class, 'devices']);
	Route::get('/admin/im/operations/sessions', [\plugin\saimulti\app\controller\admin\AdminImOperationsController::class, 'sessions']);
	Route::get('/admin/im/operations/loginAudits', [\plugin\saimulti\app\controller\admin\AdminImOperationsController::class, 'loginAudits']);
	Route::post('/admin/im/operations/deviceStatus', [\plugin\saimulti\app\controller\admin\AdminImOperationsController::class, 'deviceStatus']);
	Route::post('/admin/im/operations/revokeSession', [\plugin\saimulti\app\controller\admin\AdminImOperationsController::class, 'revokeSession']);
	Route::get('/admin/im/policy/read', [\plugin\saimulti\app\controller\admin\AdminImPolicyController::class, 'read']);
	Route::put('/admin/im/policy/update', [\plugin\saimulti\app\controller\admin\AdminImPolicyController::class, 'update']);

	//--------------------------- 系统维护 ------------------------- //
	// 数据表维护
	Route::get("/tool/database/index", [\plugin\saimulti\app\controller\tool\DataBaseController::class, 'index']);
	Route::get("/tool/database/recycle", [\plugin\saimulti\app\controller\tool\DataBaseController::class, 'recycle']);
	Route::delete("/tool/database/delete", [\plugin\saimulti\app\controller\tool\DataBaseController::class, 'delete']);
	Route::post("/tool/database/recovery", [\plugin\saimulti\app\controller\tool\DataBaseController::class, 'recovery']);
	Route::get("/tool/database/detailed", [\plugin\saimulti\app\controller\tool\DataBaseController::class, 'detailed']);
	Route::post("/tool/database/optimize", [\plugin\saimulti\app\controller\tool\DataBaseController::class, 'optimize']);
	Route::post("/tool/database/fragment", [\plugin\saimulti\app\controller\tool\DataBaseController::class, 'fragment']);

	// 附件管理
	saiMultiRoute('/tool/attachment', \plugin\saimulti\app\controller\tool\AttachmentController::class);
	Route::post("/tool/attachment/move", [\plugin\saimulti\app\controller\tool\AttachmentController::class, 'move']);
	// 附件分类
	saiMultiRoute('/tool/category', \plugin\saimulti\app\controller\system\SystemCategoryController::class);
	// 登录日志
	Route::get("/tool/loginLog/index", [\plugin\saimulti\app\controller\tool\LoginLogController::class, 'index']);
	Route::delete("/tool/loginLog/destroy", [\plugin\saimulti\app\controller\tool\LoginLogController::class, 'destroy']);
	// 操作日志
	Route::get("/tool/operateLog/index", [\plugin\saimulti\app\controller\tool\OperLogController::class, 'index']);
	Route::delete("/tool/operateLog/destroy", [\plugin\saimulti\app\controller\tool\OperLogController::class, 'destroy']);
	// 邮件日志
	Route::get("/tool/email/index", [\plugin\saimulti\app\controller\tool\MailController::class, 'index']);
	Route::delete("/tool/email/destroy", [\plugin\saimulti\app\controller\tool\MailController::class, 'destroy']);
	// 定时任务
	saiMultiRoute('/tool/crontab', \plugin\saimulti\app\controller\tool\CrontabController::class);
	Route::post("/tool/crontab/run", [\plugin\saimulti\app\controller\tool\CrontabController::class, 'run']);
	Route::get("/tool/crontab/logPageList", [\plugin\saimulti\app\controller\tool\CrontabController::class, 'logPageList']);
	Route::delete('/tool/crontab/deleteCrontabLog', [\plugin\saimulti\app\controller\tool\CrontabController::class, 'deleteCrontabLog']);

	//--------------------------- 开发中心 ------------------------- //
	saiMultiRoute("/develop/table", plugin\saimulti\app\controller\tool\TableController::class);
	Route::get("/develop/table/source", [\plugin\saimulti\app\controller\tool\TableController::class, 'source']);
	Route::get("/develop/table/sourceTable", [\plugin\saimulti\app\controller\tool\TableController::class, 'sourceTable']);
	Route::post("/develop/table/loadTable", [\plugin\saimulti\app\controller\tool\TableController::class, 'loadTable']);
	Route::get("/develop/table/preview", [\plugin\saimulti\app\controller\tool\TableController::class, 'preview']);
	Route::post("/develop/table/sync", [\plugin\saimulti\app\controller\tool\TableController::class, 'sync']);
	Route::post("/develop/table/saveDesign", [\plugin\saimulti\app\controller\tool\TableController::class, 'saveDesign']);
	Route::post("/develop/table/saveSearchDesign", [\plugin\saimulti\app\controller\tool\TableController::class, 'saveSearchDesign']);
	Route::get("/develop/table/getTableColumns", [\plugin\saimulti\app\controller\tool\TableController::class, 'getTableColumns']);
	Route::post("/develop/table/generateFile", [\plugin\saimulti\app\controller\tool\TableController::class, 'generateFile']);
	Route::post("/develop/table/generate", [\plugin\saimulti\app\controller\tool\TableController::class, 'generate']);

})->middleware([
	CheckAdminLogin::class,
	CheckAdminAuth::class,
	AdminLog::class
]);


Route::group("/saimulti", function () {
	// 租户信息
	Route::get('/tenant/user', [\plugin\saimulti\app\controller\tenant\SystemUserController::class, 'userInfo']);
	Route::get('/tenant/menu', [\plugin\saimulti\app\controller\tenant\SystemUserController::class, 'menu']);
	Route::get('/tenant/clearAllCache', [\plugin\saimulti\app\controller\TenantCommonController::class, 'clearAllCache']);
	Route::post("/tenant/updateInfo", [\plugin\saimulti\app\controller\tenant\SystemUserController::class, 'updateInfo']);
	Route::post("/tenant/modifyPassword", [\plugin\saimulti\app\controller\tenant\SystemUserController::class, 'modifyPassword']);
	Route::get('/tenant/loginList', [\plugin\saimulti\app\controller\tenant\SystemUserController::class, 'loginList']);
	Route::get('/tenant/operList', [\plugin\saimulti\app\controller\tenant\SystemUserController::class, 'operList']);

	// 常规接口
	Route::get("/tenant/dictAll", [plugin\saimulti\app\controller\TenantCommonController::class, 'dictAll']);
	Route::get("/tenant/getResourceCategory", [plugin\saimulti\app\controller\TenantCommonController::class, 'getResourceCategory']);
	Route::get("/tenant/getResourceList", [plugin\saimulti\app\controller\TenantCommonController::class, 'getResourceList']);
	Route::get("/tenant/areaCode", [plugin\saimulti\app\controller\TenantCommonController::class, 'areaCode']);
	Route::post("/tenant/uploadImage", [plugin\saimulti\app\controller\TenantCommonController::class, 'uploadImage']);
	Route::post("/tenant/uploadFile", [plugin\saimulti\app\controller\TenantCommonController::class, 'uploadFile']);

	// 租户模块启停与配置（租户不能自授权）
	Route::get('/tenant/module/index', [\plugin\saimulti\app\controller\tenant\ModuleController::class, 'index']);
	Route::post('/tenant/module/enable', [\plugin\saimulti\app\controller\tenant\ModuleController::class, 'enable']);
	Route::post('/tenant/module/disable', [\plugin\saimulti\app\controller\tenant\ModuleController::class, 'disable']);
	Route::get('/tenant/module/config', [\plugin\saimulti\app\controller\tenant\ModuleController::class, 'config']);
	Route::put('/tenant/module/config', [\plugin\saimulti\app\controller\tenant\ModuleController::class, 'updateConfig']);

	// 租户公告模块（organization 只取认证上下文）
	saiMultiRoute('/tenant/announcement', \plugin\saimulti\app\controller\tenant\AnnouncementController::class);

	// 租户 i18n（organization 只取认证上下文）
	saiMultiRoute('/tenant/i18n', \plugin\saimulti\app\controller\tenant\I18nController::class);
	Route::get('/tenant/i18n/entryIndex', [\plugin\saimulti\app\controller\tenant\I18nController::class, 'entryIndex']);
	Route::post('/tenant/i18n/entrySave', [\plugin\saimulti\app\controller\tenant\I18nController::class, 'entrySave']);
	Route::put('/tenant/i18n/entryUpdate', [\plugin\saimulti\app\controller\tenant\I18nController::class, 'entryUpdate']);
	Route::delete('/tenant/i18n/entryDestroy', [\plugin\saimulti\app\controller\tenant\I18nController::class, 'entryDestroy']);

	// 租户 favorite
	saiMultiRoute('/tenant/favorite', \plugin\saimulti\app\controller\tenant\FavoriteController::class);

	// 租户 sticker
	saiMultiRoute('/tenant/sticker', \plugin\saimulti\app\controller\tenant\StickerController::class);
	Route::get('/tenant/sticker/itemIndex', [\plugin\saimulti\app\controller\tenant\StickerController::class, 'itemIndex']);
	Route::post('/tenant/sticker/itemSave', [\plugin\saimulti\app\controller\tenant\StickerController::class, 'itemSave']);
	Route::put('/tenant/sticker/itemUpdate', [\plugin\saimulti\app\controller\tenant\StickerController::class, 'itemUpdate']);
	Route::delete('/tenant/sticker/itemDestroy', [\plugin\saimulti\app\controller\tenant\StickerController::class, 'itemDestroy']);

	// 租户 search
	$se = \plugin\saimulti\app\controller\tenant\SearchController::class;
	Route::get('/tenant/search/indexRead', [$se, 'indexRead']);
	Route::post('/tenant/search/rebuild', [$se, 'rebuild']);
	Route::get('/tenant/search/jobIndex', [$se, 'jobIndex']);
	Route::post('/tenant/search/docUpsert', [$se, 'docUpsert']);

	// 租户 moments
	Route::get('/tenant/moments/index', [\plugin\saimulti\app\controller\tenant\MomentsController::class, 'index']);
	Route::get('/tenant/moments/read', [\plugin\saimulti\app\controller\tenant\MomentsController::class, 'read']);
	Route::delete('/tenant/moments/destroy', [\plugin\saimulti\app\controller\tenant\MomentsController::class, 'destroy']);

	// 租户 file_media
	$fm = \plugin\saimulti\app\controller\tenant\FileMediaController::class;
	Route::get('/tenant/file-media/quotaRead', [$fm, 'quotaRead']);
	Route::put('/tenant/file-media/quotaUpdate', [$fm, 'quotaUpdate']);
	Route::get('/tenant/file-media/folderIndex', [$fm, 'folderIndex']);
	Route::post('/tenant/file-media/folderSave', [$fm, 'folderSave']);
	Route::put('/tenant/file-media/folderUpdate', [$fm, 'folderUpdate']);
	Route::delete('/tenant/file-media/folderDestroy', [$fm, 'folderDestroy']);
	Route::get('/tenant/file-media/itemIndex', [$fm, 'itemIndex']);
	Route::post('/tenant/file-media/itemSave', [$fm, 'itemSave']);
	Route::put('/tenant/file-media/itemUpdate', [$fm, 'itemUpdate']);
	Route::delete('/tenant/file-media/itemDestroy', [$fm, 'itemDestroy']);

	// 租户 robot_single
	$rs = \plugin\saimulti\app\controller\tenant\RobotSingleController::class;
	Route::get('/tenant/robot-single/index', [$rs, 'index']);
	Route::get('/tenant/robot-single/read', [$rs, 'read']);
	Route::post('/tenant/robot-single/save', [$rs, 'save']);
	Route::put('/tenant/robot-single/update', [$rs, 'update']);
	Route::delete('/tenant/robot-single/destroy', [$rs, 'destroy']);
	Route::get('/tenant/robot-single/ruleIndex', [$rs, 'ruleIndex']);
	Route::post('/tenant/robot-single/ruleSave', [$rs, 'ruleSave']);
	Route::put('/tenant/robot-single/ruleUpdate', [$rs, 'ruleUpdate']);
	Route::delete('/tenant/robot-single/ruleDestroy', [$rs, 'ruleDestroy']);
	Route::get('/tenant/robot-single/kbIndex', [$rs, 'kbIndex']);
	Route::post('/tenant/robot-single/kbSave', [$rs, 'kbSave']);
	Route::put('/tenant/robot-single/kbUpdate', [$rs, 'kbUpdate']);
	Route::delete('/tenant/robot-single/kbDestroy', [$rs, 'kbDestroy']);

	// 租户 customer_service
	$cs = \plugin\saimulti\app\controller\tenant\CustomerServiceController::class;
	Route::get('/tenant/customer-service/queue/index', [$cs, 'queueIndex']);
	Route::post('/tenant/customer-service/queue/save', [$cs, 'queueSave']);
	Route::put('/tenant/customer-service/queue/update', [$cs, 'queueUpdate']);
	Route::delete('/tenant/customer-service/queue/destroy', [$cs, 'queueDestroy']);
	Route::get('/tenant/customer-service/entry/index', [$cs, 'entryIndex']);
	Route::post('/tenant/customer-service/entry/save', [$cs, 'entrySave']);
	Route::put('/tenant/customer-service/entry/update', [$cs, 'entryUpdate']);
	Route::delete('/tenant/customer-service/entry/destroy', [$cs, 'entryDestroy']);
	Route::get('/tenant/customer-service/agent/index', [$cs, 'agentIndex']);
	Route::post('/tenant/customer-service/agent/save', [$cs, 'agentSave']);
	Route::put('/tenant/customer-service/agent/update', [$cs, 'agentUpdate']);
	Route::delete('/tenant/customer-service/agent/destroy', [$cs, 'agentDestroy']);
	Route::get('/tenant/customer-service/conversation/index', [$cs, 'conversationIndex']);
	Route::get('/tenant/customer-service/conversation/read', [$cs, 'conversationRead']);
	Route::put('/tenant/customer-service/conversation/update', [$cs, 'conversationUpdate']);

	// 租户 IM 运行策略（organization 只取认证上下文）
	Route::get('/tenant/im/policy/read', [\plugin\saimulti\app\controller\tenant\TenantImPolicyController::class, 'read']);
	Route::put('/tenant/im/policy/update', [\plugin\saimulti\app\controller\tenant\TenantImPolicyController::class, 'update']);
	Route::get('/tenant/routing/read', [\plugin\saimulti\app\controller\tenant\TenantRoutingController::class, 'read']);

	// 菜单列表
	Route::get("/tenant/menu/index", [plugin\saimulti\app\controller\tenant\SystemMenuController::class, 'index']);
	//--------------------------- 权限管理 ------------------------- //
	// 用户管理
	saiMultiRoute("/tenant/user", \plugin\saimulti\app\controller\tenant\SystemUserController::class);
	Route::post("/tenant/user/setHomePage", [\plugin\saimulti\app\controller\tenant\SystemUserController::class, 'setHomePage']);
	Route::post("/tenant/user/clearCache", [\plugin\saimulti\app\controller\tenant\SystemUserController::class, 'clearCache']);
	Route::post("/tenant/user/reset", [\plugin\saimulti\app\controller\tenant\SystemUserController::class, 'initUserPassword']);

	// 部门管理
	saiMultiRoute("/tenant/dept", \plugin\saimulti\app\controller\tenant\SystemDeptController::class);
	Route::get("/tenant/dept/accessDept", [\plugin\saimulti\app\controller\tenant\SystemDeptController::class, 'accessDept']);

	// 角色管理
	saiMultiRoute("/tenant/role", \plugin\saimulti\app\controller\tenant\SystemRoleController::class);
	Route::get("/tenant/role/accessRole", [\plugin\saimulti\app\controller\tenant\SystemRoleController::class, 'accessRole']);
	Route::get("/tenant/role/getMenuByRole", [\plugin\saimulti\app\controller\tenant\SystemRoleController::class, 'getMenuByRole']);
	Route::post("/tenant/role/menuPermission", [\plugin\saimulti\app\controller\tenant\SystemRoleController::class, 'menuPermission']);

	// 岗位管理
	saiMultiRoute('/tenant/post', \plugin\saimulti\app\controller\tenant\SystemPostController::class);

	// 系统配置
	Route::get("/tenant/config/basicConfig", [\plugin\saimulti\app\controller\tenant\SystemConfigController::class, 'basicConfig']);
	Route::post("/tenant/config/saveBasic", [\plugin\saimulti\app\controller\tenant\SystemConfigController::class, 'saveBasic']);
	Route::get("/tenant/config/groupConfig", [\plugin\saimulti\app\controller\tenant\SystemConfigController::class, 'groupConfig']);
	Route::post("/tenant/config/saveGroup", [\plugin\saimulti\app\controller\tenant\SystemConfigController::class, 'saveGroup']);

	// 附件管理
	Route::get("/tenant/attachment/category", [\plugin\saimulti\app\controller\tenant\SystemCategoryController::class, 'index']);
	saiMultiRoute('/tenant/attachment', \plugin\saimulti\app\controller\tenant\SystemAttachmentController::class);
	Route::post("/tenant/attachment/move", [\plugin\saimulti\app\controller\tenant\SystemAttachmentController::class, 'move']);

	// 登录日志
	Route::get("/tenant/loginLog/index", [\plugin\saimulti\app\controller\tenant\SystemLogController::class, 'getLoginLogPageList']);
	Route::delete("/tenant/loginLog/destroy", [\plugin\saimulti\app\controller\tenant\SystemLogController::class, 'deleteLoginLog']);
	// 操作日志
	Route::get("/tenant/operateLog/index", [\plugin\saimulti\app\controller\tenant\SystemLogController::class, 'getOperLogPageList']);
	Route::delete("/tenant/operateLog/destroy", [\plugin\saimulti\app\controller\tenant\SystemLogController::class, 'deleteOperLog']);

})->middleware([
	CheckTenantLogin::class,
	CheckTenantAuth::class,
	TenantLog::class
]);

// 数据中心
Route::group("/cms", function () {

	saiMultiRoute('/Article', \plugin\saimulti\app\controller\data\ArticleController::class);
	saiMultiRoute('/ArticleCategory', \plugin\saimulti\app\controller\data\ArticleCategoryController::class);
	saiMultiRoute('/ArticleBanner', \plugin\saimulti\app\controller\data\ArticleBannerController::class);

})->middleware([
	CheckTenantLogin::class,
	CheckTenantAuth::class,
	TenantLog::class
]);

// 非 Web 控制面统一预检入口。appInfo 和 Web IM 已有更具体的 OPTIONS 路由，精确路由优先。
Route::options('/saimulti/{path:.+}', static fn () => response('', 204))->middleware([
	CrossDomain::class,
]);
// 租户 CMS 数据中心与 /saimulti 同属控制面，浏览器预检必须落到 CrossDomain。
Route::options('/cms/{path:.+}', static fn () => response('', 204))->middleware([
	CrossDomain::class,
]);
