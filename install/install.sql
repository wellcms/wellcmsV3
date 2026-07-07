-- Data table structure

-- ALTER DATABASE `wellcms` charACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `well_user`;
CREATE TABLE `well_user` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, -- user ID
  `group_id` smallint(6) UNSIGNED NOT NULL DEFAULT '0',  -- User Group Number
  `email` char(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  -- Nickname
  -- `nickname` char(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `username` char(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',  -- Login unique username
  `password` char(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `salt` char(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '', -- Obfuscation Code
  -- Total number of articles
  -- `article_count` int(11) NOT NULL DEFAULT '0',
  -- Total number of comments
  -- `comment_count` int(11) NOT NULL DEFAULT '0',
  -- Total number of tags
  -- `tag_count` int(11) NOT NULL DEFAULT '0',
  `recover_count` int(11) NOT NULL DEFAULT '0', -- Total number of recover
  `credits` int(11) NOT NULL DEFAULT '0',  -- Total number of credits
  -- currency code 1CNY 2USD
  `golds` int(11) NOT NULL DEFAULT '0', -- Total number of golds
  -- `currency` tinyint(2) NOT NULL default '0',
  -- Total number of money
  -- `money` decimal(18,2) NOT NULL DEFAULT '0.00',
  `create_ip` varbinary(16) NOT NULL DEFAULT '' COMMENT '创建IP', -- Create IP
  `created_at` int(11) UNSIGNED NOT NULL DEFAULT '0', -- Creation Date
  `login_ip` varbinary(16) NOT NULL DEFAULT '' COMMENT '登录IP',  -- Login IP
  `login_date` int(11) UNSIGNED NOT NULL DEFAULT '0',  -- Login Date
  `logins` int(11) UNSIGNED NOT NULL DEFAULT '0',  -- Number of logins
  `login_version` int(11) NOT NULL DEFAULT '0',  -- 修改密码、强制下线、安全敏感操作等 version+1
  `total_logs` int(11) UNSIGNED NOT NULL DEFAULT '0',  -- Total logs
  `total_uploads` int(11) UNSIGNED NOT NULL DEFAULT '0',  -- Total uploads
  `avatar` int(11) UNSIGNED NOT NULL DEFAULT '0',  -- The time when the user last updated his profile picture
  `avatar_status` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 云储存状态 0:本地 1:已上传云储存
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_email` (`email`),
  UNIQUE KEY `idx_user_username` (`username`),
  KEY `idx_user_group` (`group_id`,`id`),
  KEY `idx_user_ip` (`create_ip`,`id`)
) ENGINE=InnoDB DEFAULT charSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 用户组
-- 用户权限：阅读、发帖、回复、编辑、删除、上传、下载、审核、创建tag；
-- 前台管理权限：置顶pinned、编辑帖子、移除帖子、删除帖子、移动、奖励、惩罚、审核帖子和回复、查看用户信息、编辑用户、禁止用户；
-- 后台权限：进入后台、系统设置、设置用户组、添加用户组、更新用户组、删除用户组、管理应用商店、安装插件、卸载插件、安装模板、卸载模板、其他功能（不影响系统运行的功能）
DROP TABLE IF EXISTS `well_group`;
CREATE TABLE `well_group` (
  `id` smallint(6) UNSIGNED NOT NULL,  -- Group ID
  `name` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '', -- 用户组名称
  `credits_from` int(11) UNSIGNED NOT NULL DEFAULT '0',	-- 积分从
  `credits_to` int(11) UNSIGNED NOT NULL DEFAULT '0',		-- 积分到
  `administer` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 进后台
  `setting` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 设置后台
  `group` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 设置用户组
  `create_group` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 创建用户组
  `update_group` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 更新用户组
  `delete_group` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 删除用户组
  `store` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 管理应用
  `plugin` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 插件安装/卸载
  `theme` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 主题安装/卸载
  `other` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 其他功能

  -- 上传权限控制
  `upload` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',
  `down` tinyint(1) UNSIGNED NOT NULL DEFAULT '1',   -- 下载文件
  `upload_daily_quota` int(11) UNSIGNED NOT NULL DEFAULT '50', -- 每日上传文件数量限制
  `upload_per_post` int(11) UNSIGNED NOT NULL DEFAULT '10', -- 单场发布上传文件数量限制
  `allowed_file_types` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 允许上传的文件类型：0: 继承全局 (Config Default) / 1: 图片类 (Lite) / 2: 文档类 (Standard) / 3: 全能/开发者 (Full)
  `quota_daily_size` bigint(20) UNSIGNED NOT NULL DEFAULT '0', -- 每日总容量限制(字节) 0为不限制
  `quota_single_size` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 单文件大小限制(字节) 0为不限制

  `user` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 管理用户
  `create_user` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 创建用户
  `update_user` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 更新用户
  `delete_user` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 删除用户
  `view_user` tinyint(1) UNSIGNED NOT NULL DEFAULT '1',  -- 查看用户信息
  `access_user` tinyint(1) UNSIGNED NOT NULL DEFAULT '1',  -- 访问其他用户主页
  `view_ip` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',  -- 查看敏感信息
  `pinned` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 置顶
  `feature` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 加精权限
  `update` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',  -- 编辑
  `remove` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',  -- 移除看不到
  `move` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',		-- 移动
  `delete` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',  -- 彻底删除
  `ban` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',  -- 禁止用户
  `reward` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',  -- 奖励
  `punishment` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',  -- 惩罚
  `review` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',  -- 核对发布内容和评论权限
  `view` tinyint(1) UNSIGNED NOT NULL DEFAULT '1', -- 查看内容
  `post` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 发主题
  `reply` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 回复帖子
  `user_update` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',  -- 编辑内容和评论
  `user_delete` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',  -- 删除内容和评论
  `username_update` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 修改用户名
  `email_update` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',  -- 修改邮箱
  `direct_post` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',  -- 0需要审核，1直接发布内容
  `direct_reply` tinyint(1) UNSIGNED NOT NULL DEFAULT '0', -- 0需要审核，1直接发布回复
  `smtp` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',  -- 1设置STMP
  `task` tinyint(1) UNSIGNED NOT NULL DEFAULT '0'  -- 定时任务 1有权限
) ENGINE=InnoDB DEFAULT charSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `well_group` ADD PRIMARY KEY (`id`);
ALTER TABLE `well_group` MODIFY `id` smallint(6) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=110;

INSERT INTO `well_group` (`id`, `name`, `credits_from`, `credits_to`, `administer`, `setting`, `group`, `create_group`, `update_group`, `delete_group`, `store`, `plugin`, `theme`, `other`, `upload`, `down`, `upload_daily_quota`, `upload_per_post`, `allowed_file_types`, `quota_daily_size`, `quota_single_size`, `user`, `create_user`, `update_user`, `delete_user`, `view_user`, `access_user`, `view_ip`, `pinned`, `feature`, `update`, `remove`, `move`, `delete`, `ban`, `reward`, `punishment`, `review`, `view`, `post`, `reply`, `user_update`, `user_delete`, `username_update`, `email_update`, `direct_post`, `direct_reply`, `smtp`, `task`) VALUES
(0, 'Guest', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0),
(1, 'Administrator', 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 10000, 100, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1),
(2, 'Super Moderator', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 100, 20, 0, 0, 10, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0),
(3, 'Moderator', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 100, 20, 0, 0, 10, 0, 0, 0, 0, 1, 1, 0, 1, 1, 1, 1, 1, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0),
(4, 'Junior Moderator', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 100, 20, 0, 0, 10, 0, 0, 0, 0, 1, 1, 0, 0, 0, 0, 1, 1, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0),
(5, 'Unverified User', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0),
(6, 'Banned User', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0),
(101, 'Lv1', 0, 50, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 10, 5, 0, 104857600, 10485760, 0, 0, 0, 0, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 0, 1, 1, 1, 1, 0, 0),
(102, 'Lv2', 50, 200, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 20, 10, 0, 104857600, 10485760, 0, 0, 0, 0, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0),
(103, 'Lv3', 200, 1000, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 30, 10, 0, 209715200, 20971520, 0, 0, 0, 0, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0),
(104, 'Lv4', 1000, 10000, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 30, 10, 0, 104857600, 10485760, 0, 0, 0, 0, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0),
(105, 'Lv5', 10000, 100000, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 50, 10, 0, 1073741824, 104857600, 0, 0, 0, 0, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0),
(106, 'Lv6', 100000, 1000000, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 50, 10, 0, 2147483648, 209715200, 0, 0, 0, 0, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0),
(107, 'Lv7', 1000000, 10000000, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 50, 10, 0, 5368709120, 524288000, 0, 0, 0, 0, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0),
(108, 'Lv8', 10000000, 100000000, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 100, 10, 0, 10737418240, 1073741824, 0, 0, 0, 0, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0),
(109, 'Lv9', 100000000, 1000000000, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 100, 10, 0, 10737418240, 1073741824, 0, 0, 0, 0, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0);

-- 第一次进入站点就写入session表，设置状态字段，status=1 第一次访问，第二次刷新时清理cookie_test，如果判断写入了cookie并且没有错误，修改status=0可正常访问站点，如果错误，则直接锁死IP
-- session 表
-- 缓存到 runtime 表。 online_0 全局 online_fid 版块。提高遍历效率。
DROP TABLE IF EXISTS `well_session`;
CREATE TABLE `well_session` (
  `id` binary(16) NOT NULL, -- session ID，随机生成且唯一
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT '0',      -- 用户ID，未登录为 0
  `request_count` int(11) UNSIGNED NOT NULL DEFAULT '0',  -- 请求次数限制
  `url` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '', -- 当前访问的 URL
  `ip` varbinary(16) NOT NULL DEFAULT '',  -- 用户IP二进制存储
  `ip_count` int(11) UNSIGNED NOT NULL default '0',  -- IP 变动计数
  `useragent` char(192) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '', -- 浏览器信息
  `data` char(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '', -- 小数据存储
  `bigdata` tinyint(1) NOT NULL default '0',        -- 是否包含大数据
  `created_at` int(11) UNSIGNED NOT NULL DEFAULT '0',-- Creation Date
  `start_date` int(11) UNSIGNED NOT NULL default '0',   -- 开始活跃时间
  `updated_at` int(11) UNSIGNED NOT NULL default '0',-- 最后活跃时间
  PRIMARY KEY (`id`),
  KEY `idx_ses_ip` (`ip`, `updated_at`),
  KEY `idx_ses_updated` (`updated_at`),
  KEY `idx_ses_user` (`user_id`, `updated_at`)
) ENGINE=InnoDB DEFAULT charSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `well_session_data`;
CREATE TABLE `well_session_data` (
  `id` binary(16) NOT NULL,  -- session ID
  `updated_at` int(11) UNSIGNED NOT NULL DEFAULT '0',	-- 最后活跃时间
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL, -- 超大数据
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT charSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `well_ip_list`;
CREATE TABLE `well_ip_list` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT '0',
  `ip` varbinary(16) NOT NULL DEFAULT '', -- 用户IP，存储为整数形式，支持IPV6
  `type` tinyint(1) NOT NULL DEFAULT '0', -- 0正常 1黑名单 2白名单
  `reason` tinyint(1) NOT NULL DEFAULT '0', -- 1:空UA 2: API比对错误 3:规定时间内超过指定次数 4:IP或UA改变 5:IP访问超限 6:UserID访问超限 7:同IP前提下SID和UA发生改变 8:空session_id
  `updated_at` int(11) UNSIGNED NOT NULL DEFAULT '0',
  `created_at` int(11) UNSIGNED NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_ip` (`ip`)
) ENGINE=InnoDB DEFAULT charSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 持久的 key value 数据存储, ttserver, mysql
DROP TABLE IF EXISTS `well_kv`;
CREATE TABLE `well_kv` (
  `key` char(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  # `expiry` int(11) UNSIGNED NOT NULL default '0',		-- 过期时间
  PRIMARY KEY(`key`)
) ENGINE=InnoDB DEFAULT charSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `well_kv` (`key`, `value`) VALUES ('setting', '{"config":{"name":"WellCMS","version":"3.0.0","official_version":"3.0.0","last_version":"0","version_date":"0","upgrade":"0","installed":0},"picture_size":{"width":400,"height":280},"setting":{"review_threads":0,"review_comments":0,"thumbnail_on":1,"save_image_on":1},"shield":[],"pinned_home":[],"pinned_global":[],"themes":{"theme":"","children":[]}}');

-- 缓存表 用来保存临时数据
DROP TABLE IF EXISTS `well_cache`;
CREATE TABLE `well_cache` (
  `key` char(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 最后更新时间戳
  `expiry` int(11) UNSIGNED NOT NULL DEFAULT '0',		-- 过期时间
  PRIMARY KEY(`key`)
) ENGINE=InnoDB DEFAULT charSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `well_log`;
CREATE TABLE `well_log` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` tinyint(3) NOT NULL DEFAULT '0', -- 1禁言用户 2解禁用户 3锁定用户 4解锁用户 5删除用户 6安装插件 7卸载插件 8安装主题 9卸载主题 10设置后台 11登入后台 12修改用户组 13创新用户组 14删除用户组 15设置tag管理 16移除tag管理 17设置tag信息 18上传tag图标 19发布帖子 20更新帖子 21移除帖子 22删除帖子 23审核帖子 24审核帖子回复 25编辑帖子 26删除帖子回复 27上传附件 28删除附件 29移动帖子 30置顶帖子 31加精帖子 32奖励用户 33惩罚用户 34修改用户资料 35修改密码 36修改邮箱 37修改用户名 38发帖奖励 39评论奖励 40上传奖励 41下载奖励 42发帖惩罚 43评论惩罚 44上传惩罚 45下载惩罚 46创建定时任务 47更新定时任务 48删除定时任务 49执行定时任务 50设置SMTP 51修改站点名称 52修改站点描述 53修改站点关键词 54修改注册设置 55修改发帖设置 56修改上传设置 57修改积分设置 58修改邮件设置 59修改水印设置 60修改其他设置 61清理缓存 62清理在线用户 63清理日志 64清理回收站 65彻底删除用户 66恢复用户 67彻底删除帖子 68恢复帖子 69彻底删除帖子回复 70恢复帖子回复 71彻底删除附件 72恢复附件 73发布tools 74更新tools 75移除tools 76删除tools 77审核tools 78审核tools回复 79编辑tools 80删除tools回复 81上传tools附件 82删除tools附件 83移动tools 84置顶tools 85加精tools 86发布download 87更新download 88移除download 89删除download 90审核download 91审核download回复 92编辑download 93删除download回复 94上传download附件 95删除download附件 96移动download 97置顶download 98加精download 99审核 100驳回 101撤销 102用户上传文件 103用户删除文件
  `recycle` tinyint(1) NOT NULL DEFAULT '0', -- 1 回收站有数据 2数据已恢复
  `recycle_id` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 操作 recover_id
  `from_user_id` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 操作者 user_id
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 被操作的用户 user_id
  `target_id` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 主题tid
  `target_title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '', -- 主题
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '', -- 备注 TODO:
  `total` int(11) NOT NULL DEFAULT '0', -- 加减人民币 金币 积分
  `created_at` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 时间戳
  `create_ip` varbinary(16) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_log_from_user` (`from_user_id`,`id`),
  KEY `idx_log_user` (`user_id`,`id`),
  KEY `idx_log_target` (`target_id`,`id`)
) ENGINE=InnoDB DEFAULT charSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 回收删除数据 附件图片只有在彻底删除时才进行清理
DROP TABLE IF EXISTS `well_recycle`;
CREATE TABLE `well_recycle` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, -- log 表主键
  `type` tinyint(2) NOT NULL DEFAULT '0', -- 1删除用户 2删除帖子 3删除帖子回复 4删除帖子附件 5删除下载主题 6删除下载评论 7删除下载附件 8删除工具主题 9删除工具评论 10删除工具附件 11删除tag 12删除tag关联
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT '0',
  `created_at` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 时间戳
  `title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `content` longtext COLLATE utf8mb4_unicode_ci NOT NULL, -- 被删除表的数据直接JSON格式储存，方便恢复
  PRIMARY KEY (`id`),
  KEY `idx_rec_user` (`user_id`,`id`)
) ENGINE=InnoDB DEFAULT charSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 导航表
DROP TABLE IF EXISTS `well_navigation`;
CREATE TABLE `well_navigation` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` tinyint(1) NOT NULL DEFAULT '0', -- 0主菜单 1子菜单
  `parent_id` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 父菜单ID
  `hide` tinyint(1) NOT NULL DEFAULT '0', -- 0显示 1隐藏
  `jump` tinyint(1) NOT NULL DEFAULT '0', -- 0站内 1跳转
  `icon` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 图标
  `count` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 二级导航数量
  `created_at` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 时间戳
  `name` varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `url` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT charSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 用户临时操作内容记录表，比如未提交的内容，上传文件，删除文件等操作记录
DROP TABLE IF EXISTS `well_temp_content`;
CREATE TABLE `well_temp_content` (
  `id` BINARY(16) NOT NULL COMMENT 'UUIDv7', -- 二进制UUID
  `module` TINYINT(2) NOT NULL DEFAULT '0', -- 按照插件功能区分业务模块:1论坛 2文章 3头像 4下载 5工具
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 被操作的用户 user_id
  `title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `data` longtext COLLATE utf8mb4_unicode_ci NOT NULL, -- 所有内容相关包含附件路径 JSON 格式储存{"data":{"user_id":"xxxx","title":"xxxx","content":"帖子内容"},"tmp_files":{"filehash":{"status":"0","filehash":"xxxx","filename":"xxx.jpg"}}} 附件及图片完全依照 attachment 表保存格式，方便提交后同步到附加表.status=1:附件存在相同文件，0:新上传文件
  `created_at` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 时间戳
  `create_ip` varbinary(16) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_tmpcon_user` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT charSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 文件存储表，保存所有上传文件的唯一信息，用于附件表的关联和去重
DROP TABLE IF EXISTS `well_file_storage`;
CREATE TABLE `well_file_storage` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `filehash` BINARY(32) NOT NULL DEFAULT '', -- SHA256文件哈希
  `newhash` BINARY(32) NOT NULL DEFAULT '', -- 清洗之后的SHA256文件哈希
  `filesize` int(10) UNSIGNED NOT NULL DEFAULT '0', -- 文件尺寸 单位字节
  `newsize` int(10) UNSIGNED NOT NULL DEFAULT '0', -- 清洗之后的文件尺寸 单位字节
  `width` mediumint(8) UNSIGNED NOT NULL DEFAULT '0', -- width > 0 则为图片
  `height` mediumint(8) UNSIGNED NOT NULL DEFAULT '0', -- height
  `is_reviewed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, -- 审核状态：0待审核 1已通过 2拒绝
  `reviewed_at` INT(11) UNSIGNED NOT NULL DEFAULT 0, -- 审核时间
  `reviewed_by` INT(11) UNSIGNED NOT NULL DEFAULT 0, -- 审核人UserID
  `exif_cleaned` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, -- 是否已清理EXIF：0未清理 1已清理
  `ref_count` int(11) NOT NULL DEFAULT '0', -- 引用次数
  `downloads` int(11) NOT NULL DEFAULT '0', -- 下载次数
  `is_image` tinyint(1) NOT NULL DEFAULT '0', -- 是否为图片
  `cloud_type` tinyint(1) NOT NULL DEFAULT '0', -- 0本地储存 1云储存 2图床
  `create_ip` varbinary(16) NOT NULL DEFAULT '', -- Create IP
  `created_at` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 文件上传时间 UNIX 时间戳，查询时传入帖子创建时间范围
  `updated_at` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 最后更新时间
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '', -- 文件名称，会过滤，并且截断，保存后的文件名，不包含URL前缀 upload_url
  `orgfilename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',  -- 原文件名
  `mime` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',  -- MIME类型
  `path` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '', -- 本地完整路径含文件名 /storage/upload/tmp/READ.md
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '', -- 文件URL /upload/tmp/READ.md
  `cloud_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '', -- 云存储URL或ID
  `filetype` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',  -- 文件类型
  `comment` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '', -- 文件注释
  -- 新增
  `review_note` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '', -- 审核备注
  `exif_data` TEXT COLLATE utf8mb4_unicode_ci, -- 图片EXIF信息JSON格式

  PRIMARY KEY (`id`),
  -- filehash索引用于去重
  UNIQUE KEY `uk_filehash_size` (`filehash`, `filesize`),

  -- 审核状态索引（加速审核查询）
  KEY `idx_review_status` (`is_reviewed`, `id`)
) ENGINE=InnoDB DEFAULT charSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `well_attachment`;
CREATE TABLE `well_attachment` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `storage_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0', -- file storage ID
  `target_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0', -- 如主题ID
  `reply_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0', -- 二级关联ID(如回复ID) reply_id 和 target_id 只能传1个，不能同时存在值
  `module` TINYINT(2) NOT NULL DEFAULT '0', -- 按照插件功能区分业务模块:1论坛 2文章 3头像 4下载 5工具
  `is_attachment` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '0=内容图片, 1=附件文件',
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 用户ID
  `downloads` int(11) NOT NULL DEFAULT '0', -- 下载次数
  `credits` int(11) NOT NULL DEFAULT '0', -- 下载需要积分
  `golds` int(11) NOT NULL DEFAULT '0', -- 下载需要金币
  `money` int(11) NOT NULL DEFAULT '0', -- 下载需要
  `create_ip` varbinary(16) NOT NULL DEFAULT '', -- Create IP
  `created_at` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 文件上传时间 UNIX 时间戳，查询时传入帖子创建时间范围
  `updated_at` int(11) UNSIGNED NOT NULL DEFAULT '0', -- 最后更新时间
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '', -- 文件名称，会过滤，并且截断，保存后的文件名，不包含URL前缀 upload_url
  `orgfilename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',  -- 原文件名

  PRIMARY KEY (`id`),

  -- 用户模块复合索引（优化上传记录查询）
  -- KEY `idx_user_module_created` (`user_id`, `module`, `id`),

  -- 支持模块 + 主题查询
  KEY `idx_module_target_storage` (`module`, `target_id`, `storage_id`),
  KEY `idx_module_reply_storage` (`module`, `reply_id`, `storage_id`),

  -- 用户维度：个人中心-我的附件
  KEY `idx_user_created` (`user_id`, `id`)

  -- 时间维度：后台统计、归档查询
  -- KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT charSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Scheduler: 调度任务持久化表（v3.2 新增）
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `well_scheduler_tasks`;
CREATE TABLE `well_scheduler_tasks` (
    `id` BINARY(16) NOT NULL COMMENT 'UUIDv7',
    `class_name` varchar(120)  NOT NULL COMMENT 'Job 类全名',
    `method_name` char(64) NOT NULL DEFAULT 'handle' COMMENT '执行方法',
    `args` TEXT COLLATE utf8mb4_unicode_ci, -- 参数 (JSON)
    `priority` tinyint(1) UNSIGNED NOT NULL DEFAULT '5' COMMENT '优先级 0-10',
    `max_retries` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '最大重试次数',
    `retry_delay` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '重试延迟秒数',
    `timeout` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '执行超时秒数',
    `callback_url` varchar(160) NULL COMMENT '回调 URL',
    `callback_method` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '回调方法 0:POST 1:GET',
    `retry_count` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '已重试次数',
    `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '任务状态 0:pending 1:retrying 2:running 3:success 4:failed 5:cancelled',
    `dedupe_key` char(120) NULL, -- 去重键 (NULL=不去重，允许多个 NULL)
    `error` TEXT NULL COMMENT '错误信息',
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间戳',
    `updated_at` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间戳',
    `scheduled_at` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '计划执行时间戳',
    `started_at` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '开始执行时间戳',
    `completed_at` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '完成时间戳',
    `heartbeat_at` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Worker 最后心跳时间戳',
    PRIMARY KEY (`id`),
    INDEX `idx_status_scheduled` (`status`, `scheduled_at`),
    UNIQUE INDEX `idx_dedupe_key` (`dedupe_key`),
    INDEX `idx_heartbeat` (`heartbeat_at`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT charSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
