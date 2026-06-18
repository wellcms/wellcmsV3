<?php

declare(strict_types=1);

namespace App\Services\System;

class MenuService
{
    /** @var \Framework\Http\Routing\UrlGeneratorInterface */
    protected $urlGenerator;
    /** @var \App\Interfaces\LanguageLoaderInterface */
    protected $language;
    /** @var \App\Services\Auth\GroupService */
    protected $groupService;

    public function __construct(
        \Framework\Http\Routing\UrlGeneratorInterface $urlGenerator,
        \App\Services\Auth\GroupService $groupService,
        ?\App\Interfaces\LanguageLoaderInterface $language = null
    ) {
        $this->urlGenerator = $urlGenerator;
        $this->groupService = $groupService;
        $this->language = $language;
    }


    public function getUserMenuData(array $user, string $param0 = ''): array
    {
        $data = ['user' => $user];

        if (empty($user['id'])) {
            $data = $this->processGuestUser($data);
        } else {
            $data = $this->processAuthenticatedUser($data, $param0);
        }

        return $data;
    }

    protected function processAuthenticatedUser(array $data, string $param0): array
    {
        // hook app_Controllers_Base_BaseController_processAuthenticatedUser_start.php

        $user = &$data['user'];
        if (isset($user['salt'])) unset($user['salt']);
        if (isset($user['password'])) unset($user['password']);

        $groupId = isset($user['group_id']) ? (int)$user['group_id'] : 0;
        $user['links'] = [];

        if ('admin' === $param0) {
            $user['links']['home_page'] = [
                'name' => $this->language->get('home_page'),
                'url' => $this->urlGenerator->url('/')
            ];
        }

        $user['links'] = array_merge($user['links'], [
            'home' => [
                'name' => $this->language->get('my_home'),
                'url' => $this->urlGenerator->url('my/home')
            ],
            'profile' => [
                'name' => $this->language->get('profile_settings'),
                'url' => $this->urlGenerator->url('my/avatar')
            ],
        ]);

        // hook app_Controllers_Base_BaseController_processAuthenticatedUser_before.php

        if ($this->groupService->access($groupId, 'administer')) {
            $data = $this->processManagePermissions($data, $param0);
        } else {
            $user['links']['logout'] = [
                'name' => $this->language->get('logout'),
                'url' => $this->urlGenerator->url('auth/logout')
            ];
        }

        // hook app_Controllers_Base_BaseController_processAuthenticatedUser_after.php

        $data = $this->processGeneralPermissions($data);

        // hook app_Controllers_Base_BaseController_processAuthenticatedUser_end.php

        return $data;
    }

    protected function processManagePermissions(array $data, string $param0): array
    {
        $user = &$data['user'];
        $groupId = (int)$user['group_id'];
        $administer = $this->groupService->access($groupId, 'administer');
        $user['administer'] = $administer;

        // hook app_Controllers_Base_BaseController_processManagePermissions_start.php

        if ('admin' !== $param0 && true === $administer) {
            $user['links']['admin'] = [
                'name' => $this->language->get('admin_dashboard'),
                'url' => $this->urlGenerator->url('admin/panel')
            ];
        }

        if ('admin' === $param0) {
            $user['links']['logout'] = [
                'name' => $this->language->get('logout'),
                'url' => $this->urlGenerator->url('admin/logout')
            ];
        } else {
            $user['links']['logout'] = [
                'name' => $this->language->get('logout'),
                'url' => $this->urlGenerator->url('auth/logout')
            ];
        }

        // hook app_Controllers_Base_BaseController_processManagePermissions_before.php

        $authority = ['pinned', 'update', 'remove', 'delete', 'move', 'ban', 'reward', 'punishment', 'review'];

        // hook app_Controllers_Base_BaseController_processManagePermissions_after.php

        foreach ($authority as $item) {
            $user['permissions']['manage_authority'][$item] = $this->groupService->access($groupId, $item);
        }

        // hook app_Controllers_Base_BaseController_processManagePermissions_end.php

        return $data;
    }

    protected function processGeneralPermissions(array $data): array
    {
        // hook app_Controllers_Base_BaseController_processGeneralPermissions_start.php

        $user = $data['user'];
        $groupId = (int)$user['group_id'];
        $authority = ['view_user', 'access_user', 'view_ip', 'view', 'post', 'reply', 'user_update', 'user_delete', 'username_update', 'email_update', 'upload', 'down', 'direct_post', 'task'];

        // hook app_Controllers_Base_BaseController_processGeneralPermissions_before.php

        foreach ($authority as $item) {
            $user['permissions']['general_authority'][$item] = $this->groupService->access($groupId, $item);
        }

        // hook app_Controllers_Base_BaseController_processGeneralPermissions_end.php

        return $data;
    }

    protected function processGuestUser(array $data): array
    {
        // hook app_Controllers_Base_BaseController_processGuestUser_start.php

        $data['user']['signin'] = ['label' => $this->language->get('sign_in'), 'url' => $this->urlGenerator->url('auth/signIn')];
        $data['user']['signup'] = ['label' => $this->language->get('sign_up'), 'url' => $this->urlGenerator->url('auth/signUp')];

        // hook app_Controllers_Base_BaseController_processGuestUser_end.php

        return $data;
    }
}
