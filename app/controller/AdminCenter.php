<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\Feedback as FeedbackModel;
use app\model\Signup as SignupModel;
use app\model\Donation as DonationModel;
use app\model\Comment as CommentModel;
use app\model\Project as ProjectModel;
use app\model\HelpArticle as HelpModel;
use app\model\News as NewsModel;
use app\model\User as UserModel;
use think\facade\Request;
use think\facade\Session;
use think\facade\View;
use think\facade\Response;
use think\facade\Db;

class AdminCenter extends BaseController
{
    // 管理员权限检查
    protected function checkAdmin()
    {
        $userId = Session::get('user_id');
        $role   = Session::get('role');

        if (!$userId || $role !== 'admin') {
            return redirect('/user/login');
        }
        return null;
    }

    // 管理员首页
    public function index()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        $userId = Session::get('user_id');
        $admin  = UserModel::find($userId);

        // 待审核项目：cp_project.status = 'offline'
        $pendingProjects = Db::name('project')
            ->where('status', 'offline')
            ->count();

        // 待审核认证：cp_user.verify_status = 'pending'
        $pendingVerifies = UserModel::where('verify_status', 'pending')->count();

        // 待解决反馈：cp_feedback.status != 'done' 或 status 为空
        $pendingFeedbacks = Db::name('feedback')
            ->where(function ($query) {
                $query->whereNull('status')
                      ->whereOr('status', '<>', 'done');
            })
            ->count();

        View::assign([
            'admin'            => $admin,
            'pendingProjects'  => $pendingProjects,
            'pendingVerifies'  => $pendingVerifies,
            'pendingFeedbacks' => $pendingFeedbacks,
        ]);

        return View::fetch('admin_center/index');
    }

    // 用户管理列表
    public function userList()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        $page = (int)Request::get('page', 1);
        if ($page < 1) $page = 1;
        $pageSize = 20;

        $query = UserModel::order('id', 'desc');

        $keyword = trim((string)Request::get('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('username', "%{$keyword}%")
                  ->whereOr('nickname', 'like', "%{$keyword}%");
            });
        }

        $total = $query->count();
        $list  = $query->page($page, $pageSize)->select();
        $totalPages = (int)ceil($total / $pageSize);

        View::assign([
            'admin'      => UserModel::find(Session::get('user_id')),
            'list'       => $list,
            'page'       => $page,
            'totalPages' => $totalPages,
            'keyword'    => $keyword,
            'total'      => $total,
        ]);

        return View::fetch('admin_center/user_list');
    }

    // 批量更新用户角色 / 状态 / 删除
    public function updateUsers()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        if (!Request::isPost()) {
            return redirect('/adminCenter/userList');
        }

        $roles   = Request::post('role', []);
        $status  = Request::post('status', []);
        $delete  = Request::post('delete', []);

        if (!empty($delete) && is_array($delete)) {
            $idsToDelete = array_keys($delete);
            if (!empty($idsToDelete)) {
                $currentId   = (int)Session::get('user_id');
                $idsToDelete = array_filter($idsToDelete, function ($id) use ($currentId) {
                    return (int)$id !== $currentId;
                });

                if (!empty($idsToDelete)) {
                    UserModel::destroy($idsToDelete);
                }
            }
        }

        $userIds = array_unique(array_merge(array_keys((array)$roles), array_keys((array)$status)));
        foreach ($userIds as $uid) {
            /** @var UserModel|null $user */
            $user = UserModel::find((int)$uid);
            if (!$user) continue;

            if (isset($roles[$uid]) && $roles[$uid] !== '') {
                $user->role = $roles[$uid];
            }
            if (isset($status[$uid]) && $status[$uid] !== '') {
                $user->status = $status[$uid];
            }
            $user->save();
        }

        return redirect('/adminCenter/userList');
    }

    // 认证审核列表
    public function verifyList()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        $page = (int)Request::get('page', 1);
        if ($page < 1) $page = 1;
        $pageSize = 10;

        $query = UserModel::where('verify_status', 'pending')
            ->order('id', 'desc');

        $keyword = trim((string)Request::get('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('username', "%{$keyword}%")
                  ->whereOr('nickname', 'like', "%{$keyword}%");
            });
        }

        $total = $query->count();
        $list  = $query->page($page, $pageSize)->select();
        $totalPages = (int)ceil($total / $pageSize);

        View::assign([
            'admin'      => UserModel::find(Session::get('user_id')),
            'list'       => $list,
            'page'       => $page,
            'totalPages' => $totalPages,
            'keyword'    => $keyword,
            'total'      => $total,
        ]);

        return View::fetch('admin_center/verify_list');
    }

    // 处理认证审核操作（通过 / 拒绝）
    public function handleVerify()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        if (!Request::isPost()) {
            return redirect('/adminCenter/verifyList');
        }

        $userId = (int)Request::post('user_id', 0);
        $action = (string)Request::post('action', '');
        $reason = trim((string)Request::post('reject_reason', ''));

        /** @var UserModel|null $user */
        $user = UserModel::find($userId);
        if (!$user) {
            return redirect('/adminCenter/verifyList');
        }

        if ($action === 'approve') {
            $user->verify_status = 'approved';
            $user->save();

            // 系统消息：认证通过
            send_system_message(
                (int)$user->id,
                '认证审核通过',
                '你的认证申请已通过，现已成为认证用户。'
            );
        } elseif ($action === 'reject') {
            $user->verify_status = 'rejected';
            if ($reason !== '') {
                $user->verify_reason = $reason;
            }
            $user->save();

            // 系统消息：认证未通过
            send_system_message(
                (int)$user->id,
                '认证审核未通过',
                '你的认证申请未通过，原因：' . ($reason ?: '请检查资料后重新提交。')
            );
        }

        return redirect('/adminCenter/verifyList');
    }

    // 捐赠管理列表
    public function donationList()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        $page = (int)Request::get('page', 1);
        if ($page < 1) $page = 1;
        $pageSize = 20;

        $type      = trim((string)Request::get('type', ''));
        $projectId = (int)Request::get('project_id', 0);

        $query = DonationModel::order('id', 'desc');

        if ($type !== '') {
            $query->where('type', $type);
        }

        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }

        $total      = $query->count();
        $donations  = $query->page($page, $pageSize)->select();
        $totalPages = (int)ceil($total / $pageSize);

        $userIds    = [];
        $projectIds = [];

        foreach ($donations as $d) {
            $userIds[]    = (int)$d->user_id;
            $projectIds[] = (int)$d->project_id;
        }

        $userIds    = array_unique($userIds);
        $projectIds = array_unique($projectIds);

        $userMap    = [];
        $projectMap = [];

        if (!empty($userIds)) {
            $users = UserModel::whereIn('id', $userIds)->select();
            foreach ($users as $u) {
                $userMap[(int)$u->id] = $u;
            }
        }

        if (!empty($projectIds)) {
            $ps = ProjectModel::whereIn('id', $projectIds)->select();
            foreach ($ps as $p) {
                $projectMap[(int)$p->id] = $p;
            }
        }

        View::assign([
            'admin'       => UserModel::find(Session::get('user_id')),
            'donations'   => $donations,
            'userMap'     => $userMap,
            'projectMap'  => $projectMap,
            'page'        => $page,
            'totalPages'  => $totalPages,
            'type'        => $type,
            'projectId'   => $projectId,
            'total'       => $total,
        ]);

        return View::fetch('admin_center/donation_list');
    }

    // 导出捐赠为 Excel(CSV)
    public function exportDonation()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        $type      = trim((string)Request::get('type', ''));
        $projectId = (int)Request::get('project_id', 0);

        $query = DonationModel::order('id', 'desc');

        if ($type !== '') {
            $query->where('type', $type);
        }
        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }

        $donations = $query->select();

        $userIds    = [];
        $projectIds = [];

        foreach ($donations as $d) {
            $userIds[]    = (int)$d->user_id;
            $projectIds[] = (int)$d->project_id;
        }

        $userIds    = array_unique($userIds);
        $projectIds = array_unique($projectIds);

        $userMap    = [];
        $projectMap = [];

        if (!empty($userIds)) {
            $users = UserModel::whereIn('id', $userIds)->select();
            foreach ($users as $u) {
                $userMap[(int)$u->id] = $u;
            }
        }

        if (!empty($projectIds)) {
            $ps = ProjectModel::whereIn('id', $projectIds)->select();
            foreach ($ps as $p) {
                $projectMap[(int)$p->id] = $p;
            }
        }

        $filename = '捐赠记录_' . date('Y-m-d_His') . '.csv';
        header('Content-Type: application/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $headers = ['ID', '捐赠类型', '捐赠人昵称', '捐赠人ID', '项目', '金额', '物资名称', '物资数量', '备注', '捐赠时间'];
        fputcsv($output, $headers);

        foreach ($donations as $d) {
            $u = $userMap[(int)$d->user_id] ?? null;
            $p = $projectMap[(int)$d->project_id] ?? null;

            $typeLabel = $d->type === 'money' ? '资金' : '物资';

            if ($u) {
                $donorName = $u->nickname ?: $u->username;
            } else {
                $donorName = '用户不存在';
            }

            $projectName = $p ? $p->title : '项目不存在';
            $amount      = $d->type === 'money' ? ($d->amount ?? '') : '';
            $goodsName   = $d->type === 'goods' ? ($d->goods_name ?? '') : '';
            $goodsQty    = $d->type === 'goods' ? ($d->goods_quantity ?? '') : '';
            $remark      = $d->remark ?? '';
            $time        = $d->create_time ?? '';

            $row = [
                (int)$d->id,
                $typeLabel,
                $donorName,
                (int)$d->user_id,
                $projectName,
                $amount,
                $goodsName,
                $goodsQty,
                $remark,
                $time,
            ];

            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    // 参与管理列表
    public function signupManage()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        $page = (int)Request::get('page', 1);
        if ($page < 1) $page = 1;
        $pageSize = 20;

        $projectId = (int)Request::get('project_id', 0);

        $query = SignupModel::order('id', 'desc');

        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }

        $total   = $query->count();
        $records = $query->page($page, $pageSize)->select();
        $totalPages = (int)ceil($total / $pageSize);

        $userIds    = [];
        $projectIds = [];

        foreach ($records as $r) {
            $userIds[]    = (int)$r->user_id;
            $projectIds[] = (int)$r->project_id;
        }

        $userIds    = array_unique($userIds);
        $projectIds = array_unique($projectIds);

        $userMap    = [];
        $projectMap = [];

        if (!empty($userIds)) {
            $users = UserModel::whereIn('id', $userIds)->select();
            foreach ($users as $u) {
                $userMap[(int)$u->id] = $u;
            }
        }

        if (!empty($projectIds)) {
            $ps = ProjectModel::whereIn('id', $projectIds)->select();
            foreach ($ps as $p) {
                $projectMap[(int)$p->id] = $p;
            }
        }

        $allProjects = ProjectModel::order('id', 'desc')->select();

        View::assign([
            'admin'       => UserModel::find(Session::get('user_id')),
            'records'     => $records,
            'userMap'     => $userMap,
            'projectMap'  => $projectMap,
            'allProjects' => $allProjects,
            'page'        => $page,
            'totalPages'  => $totalPages,
            'projectId'   => $projectId,
            'total'       => $total,
        ]);

        return View::fetch('admin_center/signup_list');
    }

    // 管理员批量修改报名状态
    public function updateSignupStatusAdmin()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        if (!Request::isPost()) {
            return '不支持的请求方式';
        }

        $projectId = (int)Request::post('project_id', 0);
        $statusArr = Request::post('status');

        if (empty($statusArr) || !is_array($statusArr)) {
            return '没有需要更新的报名记录';
        }

        if ($projectId > 0) {
            $project = ProjectModel::find($projectId);
            if (!$project) {
                return '项目不存在';
            }
        }

        foreach ($statusArr as $signupId => $status) {
            if (!in_array($status, ['signed', 'confirmed', 'finished', 'cancelled'], true)) {
                continue;
            }

            $signup = SignupModel::find((int)$signupId);
            if ($signup) {
                $signup->status = $status;
                $signup->save();

                // 可选：这里也可以给报名人发一条站内信
            }
        }

        return redirect('/adminCenter/signupManage' . ($projectId ? '?project_id='.$projectId : ''));
    }

    // 导出报名参与记录为 CSV
    public function exportSignup()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        $projectId = (int)Request::get('project_id', 0);

        $query = SignupModel::order('id', 'desc');
        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }

        $records = $query->select();

        $userIds    = [];
        $projectIds = [];

        foreach ($records as $r) {
            $userIds[]    = (int)$r->user_id;
            $projectIds[] = (int)$r->project_id;
        }

        $userIds    = array_unique($userIds);
        $projectIds = array_unique($projectIds);

        $userMap    = [];
        $projectMap = [];

        if (!empty($userIds)) {
            $users = UserModel::whereIn('id', $userIds)->select();
            foreach ($users as $u) {
                $userMap[(int)$u->id] = $u;
            }
        }

        if (!empty($projectIds)) {
            $ps = ProjectModel::whereIn('id', $projectIds)->select();
            foreach ($ps as $p) {
                $projectMap[(int)$p->id] = $p;
            }
        }

        $filename = '报名记录_' . date('Y-m-d_His') . '.csv';
        header('Content-Type: application/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $headers = ['报名ID', '项目ID', '项目名称', '用户ID', '用户昵称', '状态', '报名时间'];
        fputcsv($output, $headers);

        foreach ($records as $r) {
            $u = $userMap[(int)$r->user_id] ?? null;
            $p = $projectMap[(int)$r->project_id] ?? null;

            $nickname = $u ? ($u->nickname ?: $u->username) : '用户不存在';
            $projectTitle = $p ? $p->title : '项目不存在';

            $statusLabel = $r->status;
            if ($r->status === 'signed')      $statusLabel = '已报名';
            elseif ($r->status === 'confirmed') $statusLabel = '已确认';
            elseif ($r->status === 'finished')  $statusLabel = '已完成';
            elseif ($r->status === 'cancelled') $statusLabel = '已取消';

            $row = [
                (int)$r->id,
                (int)$r->project_id,
                $projectTitle,
                (int)$r->user_id,
                $nickname,
                $statusLabel,
                $r->signup_time ?? '',
            ];

            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    // 反馈管理列表
    public function feedbackList()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        $page = (int)Request::get('page', 1);
        if ($page < 1) $page = 1;
        $pageSize = 20;

        $status  = trim((string)Request::get('status', ''));
        $keyword = trim((string)Request::get('keyword', ''));

        $query = FeedbackModel::order('id', 'desc');

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('name', "%{$keyword}%")
                  ->whereOr('contact', 'like', "%{$keyword}%")
                  ->whereOr('message', 'like', "%{$keyword}%");
            });
        }

        $total      = $query->count();
        $list       = $query->page($page, $pageSize)->select();
        $totalPages = (int)ceil($total / $pageSize);

        View::assign([
            'admin'      => UserModel::find(Session::get('user_id')),
            'list'       => $list,
            'page'       => $page,
            'totalPages' => $totalPages,
            'status'     => $status,
            'keyword'    => $keyword,
            'total'      => $total,
        ]);

        return View::fetch('admin_center/feedback_list');
    }

    // 更新反馈状态
    public function updateFeedbackStatus()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        if (!Request::isPost()) {
            return redirect('/adminCenter/feedbackList');
        }

        $statusArr = Request::post('status');

        if (empty($statusArr) || !is_array($statusArr)) {
            return redirect('/adminCenter/feedbackList');
        }

        foreach ($statusArr as $id => $status) {
            if (!in_array($status, ['new', 'processing', 'done'], true)) {
                continue;
            }

            $fb = FeedbackModel::find((int)$id);
            if ($fb) {
                $fb->status      = $status;
                $fb->update_time = date('Y-m-d H:i:s');
                $fb->save();
            }
        }

        return redirect('/adminCenter/feedbackList');
    }

    // 新闻管理列表
    public function newsList()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        $page = (int)Request::get('page', 1);
        if ($page < 1) $page = 1;
        $pageSize = 15;

        $category = trim((string)Request::get('category', ''));
        $status   = trim((string)Request::get('status', ''));
        $keyword  = trim((string)Request::get('keyword', ''));

        $query = NewsModel::order('id', 'desc');

        if ($category !== '') {
            $query->where('category', $category);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($keyword !== '') {
            $query->whereLike('title', "%{$keyword}%");
        }

        $total  = $query->count();
        $list   = $query->page($page, $pageSize)->select();
        $totalPages = (int)ceil($total / $pageSize);

        View::assign([
            'admin'      => UserModel::find(Session::get('user_id')),
            'list'       => $list,
            'page'       => $page,
            'totalPages' => $totalPages,
            'category'   => $category,
            'status'     => $status,
            'keyword'    => $keyword,
            'total'      => $total,
        ]);

        return View::fetch('admin_center/news_list');
    }

    // 更新新闻状态
    public function updateNewsStatus()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        if (!Request::isPost()) {
            return redirect('/adminCenter/newsList');
        }

        $statusArr = Request::post('status');

        if (empty($statusArr) || !is_array($statusArr)) {
            return redirect('/adminCenter/newsList');
        }

        foreach ($statusArr as $id => $status) {
            if (!in_array($status, ['draft', 'online', 'offline'], true)) {
                continue;
            }

            $news = NewsModel::find((int)$id);
            if ($news) {
                $news->status      = $status;
                $news->update_time = date('Y-m-d H:i:s');
                $news->save();
            }
        }

        return redirect('/adminCenter/newsList');
    }

    // 删除新闻
    public function deleteNews()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        if (!Request::isPost()) {
            return redirect('/adminCenter/newsList');
        }

        $id = (int)Request::post('id', 0);
        if ($id <= 0) {
            return redirect('/adminCenter/newsList');
        }

        $news = NewsModel::find($id);
        if ($news) {
            $news->delete();
        }

        return redirect('/adminCenter/newsList');
    }

    // 评论管理列表
    public function commentList()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        $page = (int)Request::get('page', 1);
        if ($page < 1) $page = 1;
        $pageSize = 20;

        $type    = trim((string)Request::get('type', ''));
        $keyword = trim((string)Request::get('keyword', ''));

        $query = CommentModel::order('id', 'desc');

        if ($type !== '') {
            $query->where('type', $type);
        }

        if ($keyword !== '') {
            $query->whereLike('content', "%{$keyword}%");
        }

        $total    = $query->count();
        $comments = $query->page($page, $pageSize)->select();
        $totalPages = (int)ceil($total / $pageSize);

        $userIds    = [];
        $projectIds = [];
        $helpIds    = [];
        $newsIds    = [];

        foreach ($comments as $c) {
            $userIds[] = (int)$c->user_id;
            if ($c->type === 'project') {
                $projectIds[] = (int)$c->target_id;
            } elseif ($c->type === 'help') {
                $helpIds[] = (int)$c->target_id;
            } elseif ($c->type === 'news') {
                $newsIds[] = (int)$c->target_id;
            }
        }

        $userIds    = array_unique($userIds);
        $projectIds = array_unique($projectIds);
        $helpIds    = array_unique($helpIds);
        $newsIds    = array_unique($newsIds);

        $userMap    = [];
        $projectMap = [];
        $helpMap    = [];
        $newsMap    = [];

        if (!empty($userIds)) {
            $users = UserModel::whereIn('id', $userIds)->select();
            foreach ($users as $u) {
                $userMap[(int)$u->id] = $u;
            }
        }

        if (!empty($projectIds)) {
            $ps = ProjectModel::whereIn('id', $projectIds)->select();
            foreach ($ps as $p) {
                $projectMap[(int)$p->id] = $p;
            }
        }

        if (!empty($helpIds)) {
            $hs = HelpModel::whereIn('id', $helpIds)->select();
            foreach ($hs as $h) {
                $helpMap[(int)$h->id] = $h;
            }
        }

        if (!empty($newsIds)) {
            $ns = NewsModel::whereIn('id', $newsIds)->select();
            foreach ($ns as $n) {
                $newsMap[(int)$n->id] = $n;
            }
        }

        View::assign([
            'admin'      => UserModel::find(Session::get('user_id')),
            'comments'   => $comments,
            'userMap'    => $userMap,
            'projectMap' => $projectMap,
            'helpMap'    => $helpMap,
            'newsMap'    => $newsMap,
            'page'       => $page,
            'totalPages' => $totalPages,
            'type'       => $type,
            'keyword'    => $keyword,
            'total'      => $total,
        ]);

        return View::fetch('admin_center/comment_list');
    }

    // 删除评论
    public function deleteComment()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        if (!Request::isPost()) {
            return redirect('/adminCenter/commentList');
        }

        $id = (int)Request::post('id', 0);
        if ($id <= 0) {
            return redirect('/adminCenter/commentList');
        }

        $comment = CommentModel::find($id);
        if ($comment) {
            $comment->delete();
        }

        return redirect('/adminCenter/commentList');
    }

    // 项目审核列表
    public function projectAudit()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        $page = (int)Request::get('page', 1);
        if ($page < 1) $page = 1;
        $pageSize = 10;

        $query = ProjectModel::where('status', 'offline')
            ->order('create_time', 'desc');

        $keyword = trim((string)Request::get('keyword', ''));
        if ($keyword !== '') {
            $query->whereLike('title', "%{$keyword}%");
        }

        $total    = $query->count();
        $projects = $query->page($page, $pageSize)->select();
        $totalPages = (int)ceil($total / $pageSize);

        $publisherIds = [];
        foreach ($projects as $p) {
            $publisherIds[] = (int)$p->publisher_id;
        }
        $publisherIds = array_unique($publisherIds);

        $publisherMap = [];
        if (!empty($publisherIds)) {
            $users = UserModel::whereIn('id', $publisherIds)->select();
            foreach ($users as $u) {
                $publisherMap[(int)$u->id] = $u;
            }
        }

        View::assign([
            'admin'        => UserModel::find(Session::get('user_id')),
            'projects'     => $projects,
            'publisherMap' => $publisherMap,
            'page'         => $page,
            'totalPages'   => $totalPages,
            'keyword'      => $keyword,
            'total'        => $total,
        ]);

        return View::fetch('admin_center/project_audit');
    }

    // 处理项目审核（通过 / 驳回）
    public function handleProjectAudit()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        if (!Request::isPost()) {
            return redirect('/adminCenter/projectAudit');
        }

        $projectId = (int)Request::post('project_id', 0);
        $action    = (string)Request::post('action', '');
        $reason    = trim((string)Request::post('reject_reason', ''));

        /** @var ProjectModel|null $project */
        $project = ProjectModel::find($projectId);
        if (!$project) {
            return redirect('/adminCenter/projectAudit');
        }

        if ($action === 'approve') {
            $project->status       = 'online';
            $project->audit_reason = '';
            $project->save();

            // 系统消息：项目审核通过
            if ((int)$project->publisher_id > 0) {
                send_system_message(
                    (int)$project->publisher_id,
                    '项目审核通过',
                    '你的项目《'.$project->title.'》已审核通过并上线。'
                );
            }
        } elseif ($action === 'reject') {
            $project->status = 'draft';
            if ($reason !== '') {
                $project->audit_reason = $reason;
            }
            $project->save();

            // 系统消息：项目审核未通过
            if ((int)$project->publisher_id > 0) {
                send_system_message(
                    (int)$project->publisher_id,
                    '项目审核未通过',
                    '你的项目《'.$project->title.'》审核未通过，原因：'.($reason ?: '请根据要求修改后重新提交。')
                );
            }
        }

        return redirect('/adminCenter/projectAudit');
    }

    // 求助管理列表
    public function helpList()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        $page = (int)Request::get('page', 1);
        if ($page < 1) $page = 1;
        $pageSize = 15;

        $query = HelpModel::order('id', 'desc');

        $keyword = trim((string)Request::get('keyword', ''));
        $status  = trim((string)Request::get('status', ''));

        if ($keyword !== '') {
            $query->whereLike('title', "%{$keyword}%");
        }
        if ($status !== '') {
            $query->where('status', $status);
        }

        $total = $query->count();
        $helps = $query->page($page, $pageSize)->select();
        $totalPages = (int)ceil($total / $pageSize);

        $userIds = [];
        foreach ($helps as $h) {
            $userIds[] = (int)$h->user_id;
        }
        $userIds = array_unique($userIds);

        $userMap = [];
        if (!empty($userIds)) {
            $users = UserModel::whereIn('id', $userIds)->select();
            foreach ($users as $u) {
                $userMap[(int)$u->id] = $u;
            }
        }

        View::assign([
            'admin'      => UserModel::find(Session::get('user_id')),
            'helps'      => $helps,
            'userMap'    => $userMap,
            'page'       => $page,
            'totalPages' => $totalPages,
            'keyword'    => $keyword,
            'status'     => $status,
            'total'      => $total,
        ]);

        return View::fetch('admin_center/help_list');
    }

    // 删除求助文章
    public function deleteHelp()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        if (!Request::isPost()) {
            return redirect('/adminCenter/helpList');
        }

        $id = (int)Request::post('id', 0);
        if ($id <= 0) {
            return redirect('/adminCenter/helpList');
        }

        $help = HelpModel::find($id);
        if ($help) {
            $help->delete();
        }

        return redirect('/adminCenter/helpList');
    }

    // 项目管理列表
    public function projectList()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        $page = (int)Request::get('page', 1);
        if ($page < 1) $page = 1;
        $pageSize = 15;

        $query = ProjectModel::order('id', 'desc');

        $keyword = trim((string)Request::get('keyword', ''));
        $status  = trim((string)Request::get('status', ''));

        if ($keyword !== '') {
            $query->whereLike('title', "%{$keyword}%");
        }
        if ($status !== '') {
            $query->where('status', $status);
        }

        $total    = $query->count();
        $projects = $query->page($page, $pageSize)->select();
        $totalPages = (int)ceil($total / $pageSize);

        $publisherIds = [];
        foreach ($projects as $p) {
            $publisherIds[] = (int)$p->publisher_id;
        }
        $publisherIds = array_unique($publisherIds);

        $publisherMap = [];
        if (!empty($publisherIds)) {
            $users = UserModel::whereIn('id', $publisherIds)->select();
            foreach ($users as $u) {
                $publisherMap[(int)$u->id] = $u;
            }
        }

        View::assign([
            'admin'        => UserModel::find(Session::get('user_id')),
            'projects'     => $projects,
            'publisherMap' => $publisherMap,
            'page'         => $page,
            'totalPages'   => $totalPages,
            'keyword'      => $keyword,
            'status'       => $status,
            'total'        => $total,
        ]);

        return View::fetch('admin_center/project_list');
    }

    // 删除项目
    public function deleteProject()
    {
        if ($resp = $this->checkAdmin()) {
            return $resp;
        }

        if (!Request::isPost()) {
            return redirect('/adminCenter/projectList');
        }

        $id = (int)Request::post('id', 0);
        if ($id <= 0) {
            return redirect('/adminCenter/projectList');
        }

        $project = ProjectModel::find($id);
        if ($project) {
            $project->delete();
        }

        return redirect('/adminCenter/projectList');
    }
}
