<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\Project as ProjectModel;
use app\model\Signup as SignupModel;
use app\model\User as UserModel;
use app\model\Donation as DonationModel;
use app\model\Comment as CommentModel;
use think\facade\View;
use think\facade\Request;
use think\facade\Session;
use think\facade\Filesystem;

class Project extends BaseController
{
    // 项目列表（前台）
    public function index()
    {
        $page = (int)Request::get('page', 1);
        if ($page < 1) {
            $page = 1;
        }

        $pageSize = 9;

        $query = ProjectModel::alias('p')
            ->where('p.status', 'online')
            ->join('user u', 'u.id = p.publisher_id', 'LEFT')
            ->field('p.*')
            ->orderRaw("CASE WHEN u.verify_status = 'approved' THEN 1 ELSE 0 END DESC")
            ->order('p.create_time', 'desc');

        $listRaw    = $query->page($page, $pageSize)->select();
        $total      = $query->count();
        $totalPages = (int)ceil($total / $pageSize);

        $publisherIds = [];
        foreach ($listRaw as $p) {
            $publisherIds[] = (int)$p->publisher_id;
        }
        $publisherIds = array_unique(array_filter($publisherIds));

        $userMap = [];
        if (!empty($publisherIds)) {
            $users = UserModel::whereIn('id', $publisherIds)->select();
            foreach ($users as $u) {
                $userMap[(int)$u->id] = [
                    'nickname'      => $u->nickname,
                    'username'      => $u->username,
                    'verify_status' => $u->verify_status,
                ];
            }
        }

        $list = [];
        foreach ($listRaw as $p) {
            $info = $userMap[(int)$p->publisher_id] ?? null;

            if ($info) {
                $publisherNickname = $info['nickname'] ?: ($info['username'] ?? '匿名组织');
                $publisherVerified = ($info['verify_status'] === 'approved') ? 1 : 0;
            } else {
                $publisherNickname = '匿名组织';
                $publisherVerified = 0;
            }

            $list[] = [
                'id'                   => (int)$p->id,
                'title'                => (string)$p->title,
                'cover'                => (string)$p->cover,
                'summary'              => (string)$p->summary,
                'content'              => (string)$p->content,
                'location'             => (string)$p->location,
                'end_time'             => (string)$p->end_time,
                'publisher_nickname'   => $publisherNickname,
                'publisher_is_verified'=> $publisherVerified,
            ];
        }

        View::assign('list', $list);
        View::assign('page', $page);
        View::assign('totalPages', $totalPages);

        return View::fetch('project/index');
    }

// 项目详情（前台）+ 评论 + 回复
public function detail($id)
{
    /** @var ProjectModel|null $project */
    $project = ProjectModel::find($id);
    if (!$project) {
        return '项目不存在';
    }

    // 当前登录用户和角色
    $userId = Session::get('user_id');
    $role   = Session::get('role');
    $isAdmin = ($userId && $role === 'admin');

    // 非管理员只能查看已上线项目
    if (!$isAdmin && $project->status !== 'online') {
        return '项目不存在';
    }

    $publisherId   = (int)$project->publisher_id;
    $publisherName = '匿名组织';
    if ($publisherId > 0) {
        $publisherUser = UserModel::find($publisherId);
        if ($publisherUser) {
            $publisherName = $publisherUser->nickname ?: $publisherUser->username;
        }
    }

    $comments = CommentModel::where('type', 'project')
        ->where('target_id', (int)$id)
        ->order('create_time', 'asc')
        ->select();

    $userIds = [];
    foreach ($comments as $c) {
        $userIds[] = (int)$c->user_id;
    }
    $userIds = array_unique(array_filter($userIds));

    $userMap = [];
    if (!empty($userIds)) {
        $users = UserModel::whereIn('id', $userIds)->select();
        foreach ($users as $u) {
            $userMap[(int)$u->id] = [
                'nickname'      => $u->nickname,
                'username'      => $u->username,
                'verify_status' => $u->verify_status,
            ];
        }
    }

    $topComments     = [];
    $repliesByParent = [];

    foreach ($comments as $c) {
        $uInfo = $userMap[(int)$c->user_id] ?? null;
        if ($uInfo) {
            $nickname   = $uInfo['nickname'] ?: ($uInfo['username'] ?? '匿名');
            $isVerified = ($uInfo['verify_status'] === 'approved') ? 1 : 0;
        } else {
            $nickname   = '匿名';
            $isVerified = 0;
        }

        $row = [
            'id'          => $c->id,
            'user_id'     => $c->user_id,
            'nickname'    => $nickname,
            'is_verified' => $isVerified,
            'content'     => $c->content,
            'create_time' => $c->create_time,
        ];

        if ((int)$c->parent_id === 0) {
            $topComments[] = $row;
        } else {
            $repliesByParent[$c->parent_id][] = $row;
        }
    }

    $recommendList = ProjectModel::where('status', 'online')
        ->where('id', '<>', (int)$id)
        ->order('create_time', 'desc')
        ->limit(3)
        ->select()
        ->toArray();

    View::assign('project', $project);
    View::assign('publisherName', $publisherName);
    View::assign('topComments', $topComments);
    View::assign('repliesByParent', $repliesByParent);
    View::assign('recommendList', $recommendList);

    return View::fetch('project/detail');
}

    // 项目编辑页（用户中心入口）
    public function edit()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        $id      = (int)Request::get('id', 0);
        $project = null;
        $role    = Session::get('role');

        if ($id > 0) {
            $project = ProjectModel::find($id);
            if (!$project) {
                return '项目不存在';
            }
            if ($role !== 'admin' && (int)$project->publisher_id !== (int)$userId) {
                return '你没有权限编辑该项目';
            }
        }

        View::assign('project', $project);
        return View::fetch('project/edit');
    }

    // 保存项目编辑
    public function update()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        if (!Request::isPost()) {
            return redirect('/');
        }

        $id       = (int)Request::post('id', 0);
        $title    = trim((string)Request::post('title', ''));
        $cover    = trim((string)Request::post('cover', ''));
        $content  = (string)Request::post('content', '');
        $location = trim((string)Request::post('location', ''));
        $end_time = trim((string)Request::post('end_time', ''));
        $summary  = trim((string)Request::post('summary', ''));
        $contact  = trim((string)Request::post('contact', ''));
        $qrcode   = trim((string)Request::post('qrcode_image', ''));
        $action   = (string)Request::post('action', 'draft'); // draft / publish

        if ($title === '' ||
            $cover === '' ||
            $content === '' ||
            $location === '' ||
            $end_time === '' ||
            $summary === '' ||
            $contact === '') {
            return '请填写完整项目信息。';
        }

        if (strlen($end_time) === 10) {
            $end_time .= ' 23:59:59';
        }

        $role = Session::get('role');

        if ($id > 0) {
            $project = ProjectModel::find($id);
            if (!$project) {
                return '项目不存在';
            }
            if ($role !== 'admin' && (int)$project->publisher_id !== (int)$userId) {
                return '你没有权限编辑该项目';
            }
        } else {
            $project               = new ProjectModel();
            $project->publisher_id = $userId;
            $project->create_time  = date('Y-m-d H:i:s');
        }

        $project->title        = $title;
        $project->cover        = $cover;
        $project->content      = $content;
        $project->location     = $location;
        $project->end_time     = $end_time;
        $project->summary      = $summary;
        $project->contact      = $contact;
        $project->qrcode_image = $qrcode;

        if ($action === 'publish') {
            $project->status = 'offline';   // 待审核
        } else {
            $project->status = 'draft';
        }

        $project->update_time = date('Y-m-d H:i:s');
        $project->save();

        return redirect('/userCenter/posts');
    }

    public function delete()
    {
        $userId = Session::get('user_id');
        $role   = Session::get('role');

        if (!$userId) {
            return redirect('/user/login');
        }

        $id = (int)Request::get('id', 0);
        if ($id <= 0) {
            return '参数错误';
        }

        $project = ProjectModel::find($id);
        if (!$project) {
            return '项目不存在或已删除';
        }

        if ($role !== 'admin' && (int)$project->publisher_id !== (int)$userId) {
            return '你没有权限删除该项目';
        }

        $project->delete();

        return redirect('/userCenter/posts');
    }

    // 上传封面
    public function uploadCover()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return json(['code' => 0, 'msg' => '请先登录']);
        }

        if (!Request::isPost()) {
            return json(['code' => 0, 'msg' => '不支持的请求方式']);
        }

        $file = Request::file('cover');
        if (!$file) {
            return json(['code' => 0, 'msg' => '没有选择文件']);
        }

        try {
            $saveName = Filesystem::disk('public')->putFile('project_cover', $file);
            $url      = '/storage/' . $saveName;

            return json(['code' => 1, 'msg' => '上传成功', 'url' => $url]);
        } catch (\Throwable $e) {
            return json(['code' => 0, 'msg' => '上传失败：' . $e->getMessage()]);
        }
    }

    // 收款二维码上传
    public function uploadQrcode()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return json(['code' => 0, 'msg' => '请先登录']);
        }

        if (!Request::isPost()) {
            return json(['code' => 0, 'msg' => '不支持的请求方式']);
        }

        $file = Request::file('qrcode');
        if (!$file) {
            return json(['code' => 0, 'msg' => '没有选择文件']);
        }

        try {
            $saveName = Filesystem::disk('public')->putFile('project_qrcode', $file);
            $url      = '/storage/' . $saveName;

            return json(['code' => 1, 'msg' => '上传成功', 'url' => $url]);
        } catch (\Throwable $e) {
            return json(['code' => 0, 'msg' => '上传失败：' . $e->getMessage()]);
        }
    }

    // 富文本内容图片上传
    public function uploadContentImage()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return json(['errno' => 1, 'message' => '请先登录']);
        }

        if (!Request::isPost()) {
            return json(['errno' => 1, 'message' => '不支持的请求方式']);
        }

        $files = request()->file('wangeditor-uploaded-image');
        if (empty($files)) {
            return json(['errno' => 1, 'message' => '没有选择文件']);
        }

        $files = is_array($files) ? $files : [$files];

        $urls = [];
        try {
            foreach ($files as $file) {
                $saveName = Filesystem::disk('public')->putFile('project_content', $file);
                $urls[]   = '/storage/' . $saveName;
            }
        } catch (\Throwable $e) {
            return json(['errno' => 1, 'message' => '上传失败：' . $e->getMessage()]);
        }

        return json([
            'errno' => 0,
            'data'  => $urls,
        ]);
    }

    // 报名参与（前台）
    public function signup()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            Session::flash('signup_msg', '请先登录后再报名');
            Session::flash('signup_status', 'error');
            return redirect('/user/login');
        }

        if (!Request::isPost()) {
            Session::flash('signup_msg', '不支持的请求方式');
            Session::flash('signup_status', 'error');
            return redirect('/project/index');
        }

        $projectId = (int)Request::post('project_id');

        $project = ProjectModel::where('id', $projectId)
            ->where('status', 'online')
            ->find();
        if (!$project) {
            Session::flash('signup_msg', '项目不存在或未上线');
            Session::flash('signup_status', 'error');
            return redirect('/project/detail?id=' . $projectId);
        }

        $exists = SignupModel::where('user_id', $userId)
            ->where('project_id', $projectId)
            ->find();
        if ($exists) {
            Session::flash('signup_msg', '您已报名该项目，无需重复报名');
            Session::flash('signup_status', 'error');
            return redirect('/project/detail?id=' . $projectId);
        }

        $signup = SignupModel::create([
            'user_id'    => $userId,
            'project_id' => $projectId,
            'status'     => 'signed',
        ]);

        if ($signup) {
            Session::flash('signup_msg', '报名成功！');
            Session::flash('signup_status', 'success');

            // 系统消息：通知项目发起人有新报名
            if ((int)$project->publisher_id > 0 && (int)$project->publisher_id !== (int)$userId) {
                send_system_message(
                    (int)$project->publisher_id,
                    '项目收到新报名',
                    '你的项目《'.$project->title.'》有新的报名参与者。'
                );
            }
        } else {
            Session::flash('signup_msg', '报名失败，请稍后重试');
            Session::flash('signup_status', 'error');
        }

        return redirect('/project/detail?id=' . $projectId);
    }

    // 管理员批量修改报名状态（仅后台用）
    public function updateSignupStatus()
    {
        $userId = Session::get('user_id');
        $role   = Session::get('role');

        if (!$userId || $role !== 'admin') {
            return '只有管理员可以修改报名状态';
        }

        if (!Request::isPost()) {
            return '不支持的请求方式';
        }

        $projectId = (int)Request::post('project_id');
        $statusArr = Request::post('status');

        if (empty($statusArr) || !is_array($statusArr)) {
            return '没有需要更新的报名记录';
        }

        $project = ProjectModel::find($projectId);
        if (!$project) {
            return '项目不存在';
        }

        foreach ($statusArr as $signupId => $status) {
            if (!in_array($status, ['signed', 'confirmed', 'finished', 'cancelled'], true)) {
                continue;
            }

            $signup = SignupModel::where('id', (int)$signupId)
                ->where('project_id', $projectId)
                ->find();

            if ($signup) {
                $signup->status = $status;
                $signup->save();
            }
        }

        return '报名状态已更新完成，<a href="/project/signupList?projectId=' . $projectId . '">返回报名名单</a>';
    }

    // 捐赠凭证上传
    public function uploadDonateProof()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return json(['code' => 0, 'msg' => '请先登录']);
        }

        if (!Request::isPost()) {
            return json(['code' => 0, 'msg' => '不支持的请求方式']);
        }

        $file = Request::file('proof');
        if (!$file) {
            return json(['code' => 0, 'msg' => '没有选择文件']);
        }

        try {
            $saveName = Filesystem::disk('public')->putFile('donate_proof', $file);
            $url      = '/storage/' . $saveName;
            return json(['code' => 1, 'msg' => '上传成功', 'url' => $url]);
        } catch (\Throwable $e) {
            return json(['code' => 0, 'msg' => '上传失败：' . $e->getMessage()]);
        }
    }

    // 捐赠处理（前台，支持匿名）
    public function donate()
    {
        $userId = Session::get('user_id');   // 可能为空，表示匿名

        if (!Request::isPost()) {
            return '不支持的请求方式';
        }

        $projectId = (int)Request::post('project_id');
        $type      = Request::post('type', 'money');
        $amount    = Request::post('amount');
        $goodsName = Request::post('goods_name');
        $goodsQty  = Request::post('goods_quantity');
        $remark    = Request::post('remark');

        $project = ProjectModel::where('id', $projectId)
            ->where('status', 'online')
            ->find();
        if (!$project) {
            Session::flash('donate_msg', '项目不存在或未上线');
            Session::flash('donate_status', 'error');
            return redirect('/project/detail?id=' . $projectId);
        }

        if ($type === 'money') {
            if ($amount === null || $amount === '' || floatval($amount) <= 0) {
                Session::flash('donate_msg', '请输入正确的捐款金额');
                Session::flash('donate_status', 'error');
                return redirect('/project/detail?id=' . $projectId);
            }
        } elseif ($type === 'goods') {
            if (empty($goodsName) || empty($goodsQty)) {
                Session::flash('donate_msg', '物资名称和数量不能为空');
                Session::flash('donate_status', 'error');
                return redirect('/project/detail?id=' . $projectId);
            }
        } else {
            Session::flash('donate_msg', '未知的捐赠类型');
            Session::flash('donate_status', 'error');
            return redirect('/project/detail?id=' . $projectId);
        }

        $donation = DonationModel::create([
            'user_id'        => $userId ?: null,
            'project_id'     => $projectId,
            'type'           => $type,
            'amount'         => $type === 'money' ? floatval($amount) : null,
            'goods_name'     => $type === 'goods' ? $goodsName : null,
            'goods_quantity' => $type === 'goods' ? $goodsQty : null,
            'remark'         => $remark,
        ]);

        if ($donation) {
            Session::flash('donate_msg', '捐赠成功，感谢您的爱心！');
            Session::flash('donate_status', 'success');

            // 系统消息：通知项目发起人有新捐赠
            if ((int)$project->publisher_id > 0) {
                $msg = $type === 'money'
                    ? '你的项目《'.$project->title.'》收到一笔新的资金捐赠。'
                    : '你的项目《'.$project->title.'》收到一笔新的物资捐赠。';

                send_system_message(
                    (int)$project->publisher_id,
                    '项目收到新捐赠',
                    $msg
                );
            }
        } else {
            Session::flash('donate_msg', '捐赠记录保存失败，请稍后重试');
            Session::flash('donate_status', 'error');
        }

        return redirect('/project/detail?id=' . $projectId);
    }
}
