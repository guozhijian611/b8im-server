<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\web;

use plugin\saimulti\basic\WebController;
use plugin\saimulti\service\web\WebImAssetForwardService;
use plugin\saimulti\service\web\WebImAssetUrlService;
use plugin\saimulti\service\web\WebImControlService;
use plugin\saimulti\service\web\WebImAuthService;
use plugin\saimulti\service\web\WebImUploadService;
use plugin\saimulti\service\WebOrganizationResolver;
use support\Request;
use support\Response;

final class ImController extends WebController
{
    private WebImAuthService $auth;

    private WebOrganizationResolver $organizations;

    private WebImControlService $control;

    private WebImUploadService $uploads;

    private WebImAssetForwardService $assetForwards;

    private WebImAssetUrlService $assetUrls;

    public function __construct(
        ?WebImAuthService $auth = null,
        ?WebOrganizationResolver $organizations = null,
        ?WebImControlService $control = null,
        ?WebImUploadService $uploads = null,
        ?WebImAssetForwardService $assetForwards = null,
        ?WebImAssetUrlService $assetUrls = null,
    ) {
        $this->auth = $auth ?? new WebImAuthService();
        $this->organizations = $organizations ?? new WebOrganizationResolver();
        $this->control = $control ?? new WebImControlService();
        $this->uploads = $uploads ?? new WebImUploadService();
        $this->assetForwards = $assetForwards ?? new WebImAssetForwardService();
        $this->assetUrls = $assetUrls ?? new WebImAssetUrlService();
        parent::__construct();
    }

    public function login(Request $request): Response
    {
        return $this->success($this->auth->login(
            $this->organizations->fromRequest($request),
            (string) $request->input('account', ''),
            (string) $request->input('password', ''),
            (string) $request->input('device_id', ''),
            $request->getRealIp(),
        ));
    }

    public function imToken(Request $request): Response
    {
        return $this->success($this->auth->issueImToken(
            $this->webIdentity,
            (string) $request->input('device_id', ''),
            (string) $request->input('client_id', ''),
            $request->getRealIp(),
        ));
    }

    public function me(): Response
    {
        return $this->success($this->auth->me($this->webIdentity));
    }

    public function updateAvatar(Request $request): Response
    {
        return $this->success($this->auth->updateAvatar(
            $this->webIdentity,
            (string) $request->input('avatar_file_id', ''),
        ));
    }

    public function conversations(): Response
    {
        return $this->success($this->control->conversations($this->webIdentity));
    }

    public function messageGroups(): Response
    {
        return $this->success($this->control->messageGroups($this->webIdentity));
    }

    public function createMessageGroup(Request $request): Response
    {
        return $this->success($this->control->createMessageGroup(
            $this->webIdentity,
            (string) $request->input('name', ''),
        ));
    }

    public function updateConversationGroup(Request $request): Response
    {
        return $this->success($this->control->updateConversationGroup(
            $this->webIdentity,
            (string) $request->input('conversation_id', ''),
            (int) $request->input('message_group_id', 0),
        ));
    }

    public function messageConfig(): Response
    {
        return $this->success($this->control->messageConfig($this->webIdentity));
    }

    public function messages(Request $request): Response
    {
        return $this->success($this->control->messages(
            $this->webIdentity,
            (string) $request->get('conversation_id', ''),
            (string) $request->get('peer_user_id', ''),
            (int) $request->get('after_seq', 0),
            (int) $request->get('before_seq', 0),
            (int) $request->get('limit', 50),
        ));
    }

    public function markRead(Request $request): Response
    {
        return $this->success($this->control->markRead(
            $this->webIdentity,
            (string) $request->input('conversation_id', ''),
            filter_var($request->input('all', false), FILTER_VALIDATE_BOOLEAN),
        ));
    }

    public function contacts(Request $request): Response
    {
        return $this->success($this->control->contacts(
            $this->webIdentity,
            (string) $request->get('keyword', ''),
        ));
    }

    public function searchUsers(Request $request): Response
    {
        return $this->success($this->control->searchUsers(
            $this->webIdentity,
            (string) $request->get('keyword', ''),
        ));
    }

    public function requests(): Response
    {
        return $this->success($this->control->friendRequests($this->webIdentity));
    }

    public function sendFriendRequest(Request $request): Response
    {
        return $this->success($this->control->sendFriendRequest(
            $this->webIdentity,
            (string) $request->input('to_user_id', ''),
            (string) $request->input('message', ''),
        ));
    }

    public function handleFriendRequest(Request $request): Response
    {
        return $this->success($this->control->handleFriendRequest(
            $this->webIdentity,
            (int) $request->input('id', 0),
            (string) $request->input('action', ''),
        ));
    }

    public function createGroup(Request $request): Response
    {
        return $this->success($this->control->createGroup(
            $this->webIdentity,
            (string) $request->input('title', ''),
            $request->input('member_ids', []),
        ));
    }

    public function groupMembers(Request $request): Response
    {
        return $this->success($this->control->groupMembers(
            $this->webIdentity,
            (string) $request->get('conversation_id', ''),
        ));
    }

    public function addGroupMembers(Request $request): Response
    {
        return $this->success($this->control->addGroupMembers(
            $this->webIdentity,
            (string) $request->input('conversation_id', ''),
            $request->input('member_ids', []),
        ));
    }

    public function updateConversationSetting(Request $request): Response
    {
        return $this->success($this->control->updateConversationSetting(
            $this->webIdentity,
            (string) $request->input('conversation_id', ''),
            $request->input('is_pinned', null),
            $request->input('is_muted', null),
        ));
    }

    public function updateGroupProfile(Request $request): Response
    {
        return $this->success($this->control->updateGroupProfile(
            $this->webIdentity,
            (string) $request->input('conversation_id', ''),
            $request->input('title', null),
            $request->input('avatar_file_id', null),
            $request->input('description', null),
            filter_var($request->input('notify_all', false), FILTER_VALIDATE_BOOLEAN),
        ));
    }

    public function updateGroupManagers(Request $request): Response
    {
        return $this->success($this->control->updateGroupManagers(
            $this->webIdentity,
            (string) $request->input('conversation_id', ''),
            $request->input('manager_user_ids', []),
        ));
    }

    public function updateGroupMemberStatus(Request $request): Response
    {
        return $this->success($this->control->updateGroupMemberStatus(
            $this->webIdentity,
            (string) $request->input('conversation_id', ''),
            (string) $request->input('member_user_id', ''),
            (int) $request->input('status', 1),
            (string) $request->input('mute_until', ''),
        ));
    }

    public function removeGroupMember(Request $request): Response
    {
        return $this->success($this->control->removeGroupMember(
            $this->webIdentity,
            (string) $request->input('conversation_id', ''),
            (string) $request->input('member_user_id', ''),
        ));
    }

    public function updateFriendRemark(Request $request): Response
    {
        return $this->success($this->control->updateFriendRemark(
            $this->webIdentity,
            (string) $request->input('friend_user_id', ''),
            (string) $request->input('remark', ''),
        ));
    }

    public function searchMessages(Request $request): Response
    {
        return $this->success($this->control->searchMessages(
            $this->webIdentity,
            (string) $request->get('conversation_id', ''),
            (string) $request->get('keyword', ''),
            (int) $request->get('message_type', 0),
            (int) $request->get('limit', 50),
        ));
    }

    public function prepareUpload(Request $request): Response
    {
        return $this->success($this->uploads->prepare(
            $this->webIdentity,
            (string) $request->input('kind', 'file'),
            (string) $request->input('filename', ''),
            (int) $request->input('size', 0),
            (string) $request->input('mime_type', ''),
        ));
    }

    public function upload(Request $request): Response
    {
        return $this->success($this->uploads->upload(
            $this->webIdentity,
            $request,
            (string) $request->input('kind', 'file'),
        ));
    }

    public function confirmUpload(): Response
    {
        return $this->success($this->uploads->confirm($this->webIdentity));
    }

    public function deriveForwardAsset(Request $request): Response
    {
        return $this->success($this->assetForwards->derive(
            $this->webIdentity,
            (string) $request->input('conversation_id', ''),
            (string) $request->input('message_id', ''),
            (string) $request->input('file_id', ''),
            (string) $request->input('kind', ''),
        ));
    }

    public function resolveAssetUrl(Request $request): Response
    {
        return $this->success($this->assetUrls->resolve(
            $this->webIdentity,
            (string) $request->input('file_id', ''),
            (string) $request->input('conversation_id', ''),
            (string) $request->input('message_id', ''),
        ));
    }

    /** @return list<string> */
    protected function publicActions(): array
    {
        return ['login'];
    }
}
