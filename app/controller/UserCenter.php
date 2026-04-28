<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\Donation as DonationModel;
use app\model\Project as ProjectModel;
use app\model\HelpArticle as HelpModel;
use app\model\Signup as SignupModel;
use app\model\Message as MessageModel;
use app\model\User as UserModel;
use app\model\Comment as CommentModel;
use app\model\News as NewsModel;
use think\facade\Session;
use think\facade\View;
use think\facade\Db;
use think\facade\Request;
use think\facade\Filesystem;

class UserCenter extends BaseController
{
    protected function alertAndBack(string $message, string $redirectUrl = '')
    {
        $js = $redirectUrl
            ? "window.location.href = '{$redirectUrl}';"
            : "history.back();";

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>提示</title>
</head>
<body>
<script>
    alert('{$message}');
    {$js}
</script>
</body>
</html>
HTML;
    }

    // 顶部站内信：未读计数 + 最新消息
    protected function assignMessageBadge(): void
    {
        $userId = Session::get('user_id');
        $unreadCount = 0;
        $newMessages = [];

        if ($userId) {
            $query = MessageModel::where('receiver_id', $userId)
                ->where('is_read', 0)
                ->order('create_time', 'desc');

            $unreadCount = $query->count();
            $newMessages = $query->limit(5)->select();

            $senderIds = [];
            foreach ($newMessages as $m) {
                if ($m->sender_id > 0) {
                    $senderIds[] = (int)$m->sender_id;
                }
            }
            $senderIds = array_unique($senderIds);

            $senderMap = [];
            if (!empty($senderIds)) {
                $users = UserModel::whereIn('id', $senderIds)->select();
                foreach ($users as $u) {
                    $senderMap[(int)$u->id] = $u;
                }
            }

            View::assign('unreadCount', $unreadCount);
            View::assign('newMessages', $newMessages);
            View::assign('msgSenderMap', $senderMap);
        } else {
            View::assign('unreadCount', 0);
            View::assign('newMessages', []);
            View::assign('msgSenderMap', []);
        }
    }

    // 仪表盘
    public function index()
    {
        $this->assignMessageBadge();

        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        /** @var UserModel|null $user */
        $user = UserModel::find($userId);
        if (!$user) {
            return $this->alertAndBack('用户不存在或已被删除', '/user/login');
        }

        $nickname = $user->nickname ?: $user->username;
        $username = $user->username;

        $identityLabel = '普通用户';
        if (isset($user->verify_status) && ($user->verify_status === 'approved' || $user->verify_status === 1)) {
            $identityLabel = '认证用户';
        }

        $avatar = $user->avatar ?: 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=100&h=100&fit=crop';

        // 累计发布：项目 + 求助（普通用户不能发新闻，所以不统计新闻）
        $articleCount = Db::name('project')
            ->where('publisher_id', $userId)
            ->count();

        $helpCount = Db::name('help_article')
            ->where('author_id', $userId)
            ->count();

        $totalPublish = $articleCount + $helpCount;

        // 我发的所有内容 ID（只统计项目 + 求助）
        $projectIds = Db::name('project')
            ->where('publisher_id', $userId)
            ->column('id');

        $helpIds = Db::name('help_article')
            ->where('author_id', $userId)
            ->column('id');

        // 别人对我发布内容的评论（只算项目 + 求助）
        $totalPostComments = 0;

        if (!empty($projectIds)) {
            $totalPostComments += Db::name('comment')
                ->where('type', 'project')
                ->whereIn('target_id', $projectIds)
                ->where('user_id', '<>', $userId)
                ->count();
        }

        if (!empty($helpIds)) {
            $totalPostComments += Db::name('comment')
                ->where('type', 'help')
                ->whereIn('target_id', $helpIds)
                ->where('user_id', '<>', $userId)
                ->count();
        }

        // 别人回复我的评论
        $myCommentIds = Db::name('comment')
            ->where('user_id', $userId)
            ->column('id');

        $totalReplyToMe = 0;
        if (!empty($myCommentIds)) {
            $totalReplyToMe = Db::name('comment')
                ->whereIn('parent_id', $myCommentIds)
                ->where('user_id', '<>', $userId)
                ->count();
        }

        $commentCount = $totalPostComments + $totalReplyToMe;

        // 最新上线的项目和求助
        $latestProjects = Db::name('project')
            ->where('status', 'online')
            ->order('create_time desc')
            ->limit(3)
            ->select();

        $latestHelps = Db::name('help_article')
            ->where('status', 'online')
            ->order('create_time desc')
            ->limit(3)
            ->select();

        View::assign([
            'nickname'        => $nickname,
            'username'        => $username,
            'avatar'          => $avatar,
            'identityLabel'   => $identityLabel,
            'role'            => $identityLabel,
            'total_publish'   => $totalPublish,
            'comment_count'   => $commentCount,
            'latestProjects'  => $latestProjects,
            'latestHelps'     => $latestHelps,
        ]);

        return View::fetch('user_center/index');
    }

    // 我的参与：当前用户报名的公益项目
    public function participate()
    {
        $this->assignMessageBadge();

        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        /** @var UserModel|null $user */
        $user = UserModel::find($userId);
        if (!$user) {
            return redirect('/user/login');
        }

        $nickname = $user->nickname ?: $user->username;
        $username = $user->username;
        $avatar   = $user->avatar ?: 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=100&h=100&fit=crop';

        $page = (int)Request::get('page', 1);
        if ($page < 1) $page = 1;
        $pageSize = 10;

        // A. 我参与的项目
        $query = SignupModel::where('user_id', $userId)
            ->order('signup_time', 'desc');

        $total   = $query->count();
        $signups = $query->page($page, $pageSize)->select();

        $projectIds = [];
        foreach ($signups as $s) {
            $projectIds[] = (int)$s->project_id;
        }
        $projectIds = array_unique($projectIds);

        $projects = [];
        if (!empty($projectIds)) {
            $rows = ProjectModel::whereIn('id', $projectIds)->select();
            foreach ($rows as $p) {
                $projects[(int)$p->id] = $p;
            }
        }

        $myJoinList = [];
        foreach ($signups as $s) {
            $p = $projects[(int)$s->project_id] ?? null;
            $myJoinList[] = [
                'signup_id'     => $s->id,
                'project_id'    => $s->project_id,
                'project_title' => $p ? $p->title : '项目已被删除',
                'signup_time'   => $s->signup_time,
                'status'        => $s->status,
                'contact'       => $p ? $p->contact : '',
            ];
        }

        $totalPages = (int)ceil($total / $pageSize);

        // B. 我发起项目的报名
        $ownedProjects   = ProjectModel::where('publisher_id', $userId)->select();
        $ownedProjectIds = [];
        foreach ($ownedProjects as $p) {
            $ownedProjectIds[] = (int)$p->id;
        }

        $ownerSignupList = [];
        if (!empty($ownedProjectIds)) {
            $osignups = SignupModel::whereIn('project_id', $ownedProjectIds)
                ->order('signup_time', 'desc')
                ->select();

            $signupUserIds = [];
            foreach ($osignups as $s) {
                $signupUserIds[] = (int)$s->user_id;
            }
            $signupUserIds = array_unique($signupUserIds);

            $userMap = [];
            if (!empty($signupUserIds)) {
                $uRows = UserModel::whereIn('id', $signupUserIds)->select();
                foreach ($uRows as $u) {
                    $userMap[(int)$u->id] = $u;
                }
            }

            $projMap = [];
            foreach ($ownedProjects as $p) {
                $projMap[(int)$p->id] = $p;
            }

            foreach ($osignups as $s) {
                $p = $projMap[(int)$s->project_id] ?? null;
                $u = $userMap[(int)$s->user_id] ?? null;
                $ownerSignupList[] = [
                    'signup_id'     => $s->id,
                    'project_id'    => $s->project_id,
                    'project_title' => $p ? $p->title : '项目已被删除',
                    'user_id'       => $s->user_id,
                    'username'      => $u ? ($u->nickname ?: $u->username) : '用户已不存在',
                    'phone'         => $u ? ($u->phone ?? '')   : '',
                    'email'         => $u ? ($u->email ?? '')   : '',
                    'signup_time'   => $s->signup_time,
                    'status'        => $s->status,
                ];
            }
        }

        View::assign([
            'list'            => $myJoinList,
            'page'            => $page,
            'totalPages'      => $totalPages,
            'ownerSignupList' => $ownerSignupList,
            'nickname'        => $nickname,
            'username'        => $username,
            'avatar'          => $avatar,
        ]);

        return View::fetch('user_center/participate');
    }

    // 项目发起人修改报名状态（并给报名人发站内信）
    public function updateSignupStatus()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        $signupId = (int)Request::post('signup_id', 0);
        $status   = trim((string)Request::post('status', ''));

        if ($signupId <= 0 || $status === '') {
            return '参数错误';
        }

        /** @var SignupModel|null $signup */
        $signup = SignupModel::find($signupId);
        if (!$signup) {
            return '报名记录不存在';
        }

        $project = ProjectModel::find($signup->project_id);
        if (!$project || (int)$project->publisher_id !== (int)$userId) {
            return '你没有权限修改该报名状态';
        }

        $allowed = ['signed', 'confirmed', 'finished', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            return '非法状态值';
        }

        $signup->status      = $status;
        $signup->update_time = date('Y-m-d H:i:s');
        $signup->save();

        // 系统站内信：通知报名人
        if ((int)$signup->user_id > 0) {
            $statusMap = [
                'signed'    => '已报名',
                'confirmed' => '已确认',
                'finished'  => '已完成',
                'cancelled' => '已取消',
            ];
            $statusText = $statusMap[$status] ?? $status;
            send_system_message(
                (int)$signup->user_id,
                '报名状态更新',
                '你在项目《'.$project->title.'》的报名状态已变更为：'.$statusText.'。'
            );
        }

        return redirect('/userCenter/participate');
    }

    public function exportSignupCsv()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        $ownedProjects = ProjectModel::where('publisher_id', $userId)->select();
        $ownedProjectIds = [];
        foreach ($ownedProjects as $p) {
            $ownedProjectIds[] = (int)$p->id;
        }

        if (empty($ownedProjectIds)) {
            return $this->alertAndBack('你还没有发起任何项目，无报名数据可导出', '/userCenter/participate');
        }

        $signups = SignupModel::whereIn('project_id', $ownedProjectIds)
            ->order('project_id', 'asc')
            ->order('signup_time', 'asc')
            ->select();

        if ($signups->isEmpty()) {
            return $this->alertAndBack('暂无报名数据可导出', '/userCenter/participate');
        }

        $userIds    = [];
        $projectMap = [];
        foreach ($signups as $s) {
            $userIds[] = (int)$s->user_id;
        }
        $userIds = array_unique($userIds);

        $users = UserModel::whereIn('id', $userIds)->select();
        $userMap = [];
        foreach ($users as $u) {
            $userMap[(int)$u->id] = $u;
        }

        foreach ($ownedProjects as $p) {
            $projectMap[(int)$p->id] = $p;
        }

        $filename = 'signup_export_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        echo "\xEF\xBB\xBF";

        $fp = fopen('php://output', 'w');

        fputcsv($fp, ['项目ID','项目名称','报名人ID','报名人','手机号','邮箱','报名时间','状态']);

        $statusMap = [
            'signed'    => '已报名',
            'confirmed' => '已确认',
            'finished'  => '已完成',
            'cancelled' => '已取消',
        ];

        foreach ($signups as $s) {
            $p = $projectMap[(int)$s->project_id] ?? null;
            $u = $userMap[(int)$s->user_id] ?? null;

            $projectTitle = $p ? $p->title : '项目已被删除';
            $username     = $u ? ($u->nickname ?: $u->username) : '用户已不存在';
            $phone        = $u ? ($u->phone ?? '') : '';
            $email        = $u ? ($u->email ?? '') : '';

            $statusText = $statusMap[$s->status] ?? $s->status;

            fputcsv($fp, [
                $s->project_id,
                $projectTitle,
                $s->user_id,
                $username,
                $phone,
                $email,
                $s->signup_time,
                $statusText,
            ]);
        }

        fclose($fp);
        exit;
    }

    public function cancelSignup()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        $id = (int)Request::get('id', 0);
        if ($id <= 0) {
            return '参数错误';
        }

        /** @var SignupModel|null $signup */
        $signup = SignupModel::find($id);
        if (!$signup) {
            return '报名记录不存在或已删除';
        }

        if ((int)$signup->user_id !== (int)$userId) {
            return '你没有权限取消这条报名';
        }

        $signup->status      = 'cancelled';
        $signup->update_time = date('Y-m-d H:i:s');
        $signup->save();

        return redirect('/userCenter/participate');
    }

    // 用户设置页
    public function settings()
    {
        $this->assignMessageBadge();

        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        /** @var UserModel|null $user */
        $user = UserModel::find($userId);
        if (!$user) {
            return redirect('/user/login');
        }

        $nickname = $user->nickname ?: $user->username;
        $username = $user->username;
        $avatar   = $user->avatar ?: 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=100&h=100&fit=crop';

        View::assign([
            'user'     => $user,
            'nickname' => $nickname,
            'username' => $username,
            'avatar'   => $avatar,
        ]);

        return View::fetch('user_center/settings');
    }

    // 提交修改密码
    public function updatePassword()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        if (!Request::isPost()) {
            return redirect('/userCenter/settings');
        }

        $oldPassword = (string)Request::post('old_password', '');
        $newPassword = (string)Request::post('new_password', '');
        $confirm     = (string)Request::post('confirm_password', '');

        /** @var UserModel|null $user */
        $user = UserModel::find($userId);
        if (!$user) {
            return redirect('/user/login');
        }

        if ($newPassword === '' || $confirm === '') {
            return '新密码不能为空';
        }
        if ($newPassword !== $confirm) {
            return '两次输入的新密码不一致';
        }

        if (!password_verify($oldPassword, $user->password)) {
            return '旧密码不正确';
        }

        $user->password = password_hash($newPassword, PASSWORD_DEFAULT);
        $user->save();

        return '密码修改成功，请下次使用新密码登录。';
    }

    public function updateContact()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        if (!Request::isPost()) {
            return redirect('/userCenter/settings');
        }

        $phone = trim((string)Request::post('phone', ''));
        $email = trim((string)Request::post('email', ''));

        if ($phone === '' || $email === '') {
            return $this->alertAndBack('手机号和邮箱不能为空', '/userCenter/settings');
        }

        /** @var UserModel|null $user */
        $user = UserModel::find($userId);
        if (!$user) {
            return $this->alertAndBack('用户不存在或已被删除', '/user/login');
        }

        $user->phone = $phone;
        $user->email = $email;
        $user->save();

        return $this->alertAndBack('联系方式已更新', '/userCenter/settings');
    }

    // 设置/修改密保
    public function updateSecurity()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        if (!Request::isPost()) {
            return redirect('/userCenter/settings');
        }

        $question = trim((string)Request::post('security_question', ''));
        $answer   = trim((string)Request::post('security_answer', ''));

        /** @var UserModel|null $user */
        $user = UserModel::find($userId);
        if (!$user) {
            return redirect('/user/login');
        }

        if ($question === '' || $answer === '') {
            return '密保问题和答案不能为空';
        }

        $user->security_question = $question;
        $user->security_answer   = $answer;
        $user->save();

        return '密保设置已更新。';
    }

    // 上传头像
    public function uploadAvatar()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return json(['code' => 0, 'msg' => '请先登录']);
        }

        if (!Request::isPost()) {
            return json(['code' => 0, 'msg' => '不支持的请求方式']);
        }

        $file = Request::file('avatar');
        if (!$file) {
            return json(['code' => 0, 'msg' => '没有选择文件']);
        }

        try {
            $saveName = Filesystem::disk('public')->putFile('avatar', $file);
            $url = '/storage/' . $saveName;

            /** @var UserModel|null $user */
            $user = UserModel::find($userId);
            if ($user) {
                $user->avatar = $url;
                $user->save();
            }

            return json(['code' => 1, 'msg' => '上传成功', 'url' => $url]);
        } catch (\Throwable $e) {
            return json(['code' => 0, 'msg' => '上传失败：' . $e->getMessage()]);
        }
    }

    // 上传认证证件照片
    public function uploadVerifyImage()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return json(['code' => 0, 'msg' => '请先登录']);
        }

        if (!Request::isPost()) {
            return json(['code' => 0, 'msg' => '不支持的请求方式']);
        }

        $file = Request::file('verify_image');
        if (!$file) {
            return json(['code' => 0, 'msg' => '没有选择文件']);
        }

        try {
            $saveName = Filesystem::disk('public')->putFile('verify', $file);
            $url = '/storage/' . $saveName;

            return json(['code' => 1, 'msg' => '上传成功', 'url' => $url]);
        } catch (\Throwable $e) {
            return json(['code' => 0, 'msg' => '上传失败：' . $e->getMessage()]);
        }
    }

    // 认证升级申请
    public function applyVerify()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        if (!Request::isPost()) {
            return redirect('/userCenter/settings');
        }

        $reason = trim((string)Request::post('verify_reason', ''));

        /** @var UserModel|null $user */
        $user = UserModel::find($userId);
        if (!$user) {
            return redirect('/user/login');
        }

        $verifyImage = trim((string)Request::post('verify_image', ''));

        if ($reason === '' || $verifyImage === '') {
            return '请填写认证说明并上传证件照片';
        }

        $user->verify_status = 'pending';
        $user->verify_reason = $reason;
        $user->verify_image  = $verifyImage;
        $user->save();

        return '认证申请已提交，请等待管理员审核。';
    }

    // 评论管理：我发的评论 + 别人回复我的评论
    public function comments()
    {
        $this->assignMessageBadge();

        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        /** @var UserModel|null $user */
        $user = UserModel::find($userId);
        if (!$user) {
            return redirect('/user/login');
        }

        $nickname = $user->nickname ?: $user->username;
        $username = $user->username;
        $avatar   = $user->avatar ?: 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=100&h=100&fit=crop';

        $page = (int)Request::get('page', 1);
        if ($page < 1) $page = 1;
        $pageSize = 10;

        $myQuery = CommentModel::where('user_id', $userId)
            ->order('create_time', 'desc');

        $myTotal   = $myQuery->count();
        $myRecords = $myQuery->page($page, $pageSize)->select();

        $projectTargetIds = [];
        $helpTargetIds    = [];
        $newsTargetIds    = [];

        foreach ($myRecords as $c) {
            if ($c->type === 'project') {
                $projectTargetIds[] = (int)$c->target_id;
            } elseif ($c->type === 'help') {
                $helpTargetIds[] = (int)$c->target_id;
            } elseif ($c->type === 'news') {
                $newsTargetIds[] = (int)$c->target_id;
            }
        }

        $projectTargetIds = array_unique($projectTargetIds);
        $helpTargetIds    = array_unique($helpTargetIds);
        $newsTargetIds    = array_unique($newsTargetIds);

        $projectMap = [];
        if (!empty($projectTargetIds)) {
            $ps = ProjectModel::whereIn('id', $projectTargetIds)->select();
            foreach ($ps as $p) {
                $projectMap[(int)$p->id] = $p;
            }
        }

        $helpMap = [];
        if (!empty($helpTargetIds)) {
            $hs = HelpModel::whereIn('id', $helpTargetIds)->select();
            foreach ($hs as $h) {
                $helpMap[(int)$h->id] = $h;
            }
        }

        $newsMap = [];
        if (!empty($newsTargetIds)) {
            $ns = NewsModel::whereIn('id', $newsTargetIds)->select();
            foreach ($ns as $n) {
                $newsMap[(int)$n->id] = $n;
            }
        }

        $myList = [];
        foreach ($myRecords as $c) {
            $type = $c->type;
            if ($type === 'project') {
                $target   = $projectMap[(int)$c->target_id] ?? null;
                $title    = $target ? $target->title : '项目已被删除';
                $link     = $target ? '/project/detail?id=' . $target->id . '#comment-' . $c->id : '#';
                $typeName = '公益项目';
            } elseif ($type === 'help') {
                $target   = $helpMap[(int)$c->target_id] ?? null;
                $title    = $target ? $target->title : '求助已被删除';
                $link     = $target ? '/help/detail?id=' . $target->id . '#comment-' . $c->id : '#';
                $typeName = '求助文章';
            } elseif ($type === 'news') {
                $target   = $newsMap[(int)$c->target_id] ?? null;
                $title    = $target ? $target->title : '新闻已被删除';
                $link     = $target ? '/news/detail?id=' . $target->id . '#comment-' . $c->id : '#';
                $typeName = '新闻案例';
            } else {
                $target   = null;
                $title    = '内容已被删除';
                $link     = '#';
                $typeName = '其他';
            }

            $myList[] = [
                'id'          => $c->id,
                'type'        => $type,
                'type_name'   => $typeName,
                'target_id'   => $c->target_id,
                'target_title'=> $title,
                'link'        => $link,
                'content'     => $c->content,
                'create_time' => $c->create_time,
            ];
        }

        $myCommentIds = array_column($myList, 'id');
        $replyList    = [];

        if (!empty($myCommentIds)) {
            $replyQuery = CommentModel::whereIn('parent_id', $myCommentIds)
                ->where('user_id', '<>', $userId)
                ->order('create_time', 'desc');

            $replyRecords = $replyQuery->select();

            $myCommentMap = [];
            foreach ($myRecords as $c) {
                $myCommentMap[(int)$c->id] = $c;
            }

            foreach ($replyRecords as $r) {
                $parent = $myCommentMap[(int)$r->parent_id] ?? null;

                if ($r->type === 'project') {
                    $target   = $projectMap[(int)$r->target_id] ?? null;
                    $title    = $target ? $target->title : '项目已被删除';
                    $link     = $target ? '/project/detail?id=' . $target->id . '#comment-' . $r->id : '#';
                    $typeName = '公益项目';
                } elseif ($r->type === 'help') {
                    $target   = $helpMap[(int)$r->target_id] ?? null;
                    $title    = $target ? $target->title : '求助已被删除';
                    $link     = $target ? '/help/detail?id=' . $target->id . '#comment-' . $r->id : '#';
                    $typeName = '求助文章';
                } elseif ($r->type === 'news') {
                    $target   = $newsMap[(int)$r->target_id] ?? null;
                    $title    = $target ? $target->title : '新闻已被删除';
                    $link     = $target ? '/news/detail?id=' . $target->id . '#comment-' . $r->id : '#';
                    $typeName = '新闻案例';
                } else {
                    $target   = null;
                    $title    = '内容已被删除';
                    $link     = '#';
                    $typeName = '其他';
                }

                $fromUser = UserModel::find($r->user_id);

                $replyList[] = [
                    'id'             => $r->id,
                    'type'           => $r->type,
                    'type_name'      => $typeName,
                    'target_id'      => $r->target_id,
                    'target_title'   => $title,
                    'link'           => $link,
                    'from_user'      => $fromUser ? ($fromUser->nickname ?: $fromUser->username) : '用户已不存在',
                    'content'        => $r->content,
                    'create_time'    => $r->create_time,
                    'parent_content' => $parent ? $parent->content : '',
                ];
            }
        }

        View::assign([
            'myList'     => $myList,
            'replyList'  => $replyList,
            'page'       => $page,
            'nickname'   => $nickname,
            'username'   => $username,
            'avatar'     => $avatar,
            'totalPages' => $myTotal ? (int)ceil($myTotal / $pageSize) : 1,
        ]);

        return View::fetch('user_center/comments');
    }

    // 我的捐赠：当前用户所有捐赠记录
    public function donation()
    {
        $this->assignMessageBadge();

        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        /** @var UserModel|null $user */
        $user = UserModel::find($userId);
        if (!$user) {
            return redirect('/user/login');
        }

        $nickname = $user->nickname ?: $user->username;
        $username = $user->username;
        $avatar   = $user->avatar ?: 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=100&h=100&fit=crop';

        $page = (int)Request::get('page', 1);
        if ($page < 1) $page = 1;
        $pageSize = 10;

        $query = DonationModel::where('user_id', $userId)
            ->order('donate_time', 'desc');

        $total   = $query->count();
        $records = $query->page($page, $pageSize)->select();

        $projectIds = [];
        foreach ($records as $r) {
            $projectIds[] = (int)$r->project_id;
        }
        $projectIds = array_unique($projectIds);

        $projects = [];
        if (!empty($projectIds)) {
            $rows = ProjectModel::whereIn('id', $projectIds)->select();
            foreach ($rows as $p) {
                $projects[(int)$p->id] = $p;
            }
        }

        $myDonationList = [];
        $myMoneyTotal   = 0;
        foreach ($records as $r) {
            $p = $projects[(int)$r->project_id] ?? null;
            $projectTitle = $p ? $p->title : '项目已被删除';

            $myDonationList[] = [
                'id'             => $r->id,
                'project_id'     => $r->project_id,
                'project_title'  => $projectTitle,
                'type'           => $r->type,
                'amount'         => $r->amount,
                'goods_name'     => $r->goods_name,
                'goods_quantity' => $r->goods_quantity,
                'remark'         => $r->remark,
                'donate_time'    => $r->donate_time,
            ];

            if ($r->type === 'money') {
                $myMoneyTotal += (float)$r->amount;
            }
        }

        $totalPages = (int)ceil($total / $pageSize);

        // 我发起项目收到的捐赠
        $projectIncomeList  = [];
        $projectMoneyTotal  = 0;

        $ownedProjects = ProjectModel::where('publisher_id', $userId)->select();
        $ownedProjectIds = [];
        foreach ($ownedProjects as $p) {
            $ownedProjectIds[] = (int)$p->id;
        }

        if (!empty($ownedProjectIds)) {
            $donations = DonationModel::whereIn('project_id', $ownedProjectIds)
                ->order('donate_time', 'desc')
                ->select();

            $ownedProjMap = [];
            foreach ($ownedProjects as $p) {
                $ownedProjMap[(int)$p->id] = $p;
            }

            $donorIds = [];
            foreach ($donations as $d) {
                $donorIds[] = (int)$d->user_id;
            }
            $donorIds = array_unique($donorIds);

            $userMap = [];
            if (!empty($donorIds)) {
                $donors = UserModel::whereIn('id', $donorIds)->select();
                foreach ($donors as $u) {
                    $userMap[(int)$u->id] = $u;
                }
            }

            foreach ($donations as $d) {
                $p = $ownedProjMap[(int)$d->project_id] ?? null;
                $u = $userMap[(int)$d->user_id] ?? null;

                $projectTitle = $p ? $p->title : '项目已被删除';
                if (empty($d->user_id)) {
                    $donorName = '匿名';
                } else {
                    $donorName = $u ? ($u->nickname ?: $u->username) : '用户已不存在';
                }

                $projectIncomeList[] = [
                    'id'             => $d->id,
                    'project_id'     => $d->project_id,
                    'project_title'  => $projectTitle,
                    'donor_id'       => $d->user_id,
                    'donor_name'     => $donorName,
                    'type'           => $d->type,
                    'amount'         => $d->amount,
                    'goods_name'     => $d->goods_name,
                    'goods_quantity' => $d->goods_quantity,
                    'remark'         => $d->remark,
                    'donate_time'    => $d->donate_time,
                ];

                if ($d->type === 'money') {
                    $projectMoneyTotal += (float)$d->amount;
                }
            }
        }

        View::assign([
            'nickname'           => $nickname,
            'username'           => $username,
            'avatar'             => $avatar,
            'myDonationList'     => $myDonationList,
            'myMoneyTotal'       => $myMoneyTotal,
            'page'               => $page,
            'totalPages'         => $totalPages,
            'projectIncomeList'  => $projectIncomeList,
            'projectMoneyTotal'  => $projectMoneyTotal,
        ]);

        return View::fetch('user_center/donation');
    }

    public function exportDonationCsv()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        $ownedProjects = ProjectModel::where('publisher_id', $userId)->select();
        $ownedProjectIds = [];
        foreach ($ownedProjects as $p) {
            $ownedProjectIds[] = (int)$p->id;
        }

        if (empty($ownedProjectIds)) {
            return '你还没有发起任何项目，无捐赠数据可导出';
        }

        $donations = DonationModel::whereIn('project_id', $ownedProjectIds)
            ->order('project_id', 'asc')
            ->order('donate_time', 'asc')
            ->select();

        if ($donations->isEmpty()) {
            return '暂无捐赠数据可导出';
        }

        $projectMap = [];
        foreach ($ownedProjects as $p) {
            $projectMap[(int)$p->id] = $p;
        }

        $donorIds = [];
        foreach ($donations as $d) {
            $donorIds[] = (int)$d->user_id;
        }
        $donorIds = array_unique($donorIds);

        $userMap = [];
        if (!empty($donorIds)) {
            $donors = UserModel::whereIn('id', $donorIds)->select();
            foreach ($donors as $u) {
                $userMap[(int)$u->id] = $u;
            }
        }

        $filename = 'donation_export_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        echo "\xEF\xBB\xBF";

        $fp = fopen('php://output', 'w');

        fputcsv($fp, [
            '项目ID',
            '项目名称',
            '捐赠人ID',
            '捐赠人',
            '类型',
            '金额',
            '物资名称',
            '物资数量',
            '备注',
            '捐赠时间',
        ]);

        foreach ($donations as $d) {
            $p = $projectMap[(int)$d->project_id] ?? null;
            $u = $userMap[(int)$d->user_id] ?? null;
            $projectTitle = $p ? $p->title : '项目已被删除';

            if (empty($d->user_id)) {
                $donorName = '匿名';
            } else {
                $donorName = $u ? ($u->nickname ?: $u->username) : '用户已不存在';
            }

            $typeText = ($d->type === 'money') ? '资金' : '物资';

            fputcsv($fp, [
                $d->project_id,
                $projectTitle,
                $d->user_id,
                $donorName,
                $typeText,
                $d->amount,
                $d->goods_name,
                $d->goods_quantity,
                $d->remark,
                $d->donate_time,
            ]);
        }

        fclose($fp);
        exit;
    }

    // 我的发布：当前用户发布的项目 + 求助（去掉新闻）
    public function posts()
    {
        $this->assignMessageBadge();

        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        /** @var UserModel|null $user */
        $user = UserModel::find($userId);
        if (!$user) {
            return redirect('/user/login');
        }

        $nickname = $user->nickname ?: $user->username;
        $username = $user->username;
        $avatar   = $user->avatar ?: 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=100&h=100&fit=crop';

        $page = (int)Request::get('page', 1);
        if ($page < 1) $page = 1;
        $pageSize = 10;

        $projects = Db::name('project')
            ->where('publisher_id', $userId)
            ->field('id,title,create_time,status')
            ->select()
            ->toArray();

        $helps = Db::name('help_article')
            ->where('author_id', $userId)
            ->field('id,title,create_time,status')
            ->select()
            ->toArray();

        foreach ($projects as &$p) {
            $p['type'] = 'project';
        }
        unset($p);

        foreach ($helps as &$h) {
            $h['type'] = 'help';
        }
        unset($h);

        $all = array_merge($projects, $helps);

        usort($all, function ($a, $b) {
            return strcmp($b['create_time'], $a['create_time']);
        });

        $total = count($all);
        $totalPages = (int)ceil($total / $pageSize);
        if ($page > $totalPages && $totalPages > 0) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $pageSize;
        $list = array_slice($all, $offset, $pageSize);

        View::assign([
            'list'       => $list,
            'page'       => $page,
            'totalPages' => $totalPages,
            'nickname'   => $nickname,
            'username'   => $username,
            'avatar'     => $avatar,
        ]);

        return View::fetch('user_center/posts');
    }
}
