<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\HelpArticle as HelpModel;
use app\model\Comment as CommentModel;
use app\model\User as UserModel;
use think\facade\Request;
use think\facade\Session;
use think\facade\View;
use think\facade\Db;

class Help extends BaseController
{
// 求助文章列表（只显示 online 的）
public function index()
{
    $page = (int)Request::get('page', 1);
    if ($page < 1) $page = 1;

    $pageSize = 5;

    $query = HelpModel::where('status', 'online')
        ->order('create_time', 'desc');

    $list       = $query->page($page, $pageSize)->select();
    $total      = $query->count();
    $totalPages = (int)ceil($total / $pageSize);

    // 统计每篇求助的评论数量
    $ids = [];
    foreach ($list as $item) {
        $ids[] = (int)$item->id;
    }

    $replyCountMap = [];
    if (!empty($ids)) {
        $rows = Db::name('comment')
            ->field('target_id, COUNT(*) AS c')
            ->where('type', 'help')
            ->whereIn('target_id', $ids)
            ->group('target_id')
            ->select();

        foreach ($rows as $row) {
            $replyCountMap[(int)$row['target_id']] = (int)$row['c'];
        }
    }

    foreach ($list as $item) {
        $id = (int)$item->id;
        $item->reply_count = $replyCountMap[$id] ?? 0;
    }

    // ===== 给列表每条求助补上作者昵称、头像、认证标记 =====
    $authorIds = [];
    foreach ($list as $item) {
        if (!empty($item->author_id)) {
            $authorIds[] = (int)$item->author_id;
        }
    }
    $authorIds = array_unique($authorIds);

    $authorMap = [];
    if (!empty($authorIds)) {
        $users = UserModel::whereIn('id', $authorIds)->select();
        foreach ($users as $u) {
            $authorMap[(int)$u->id] = [
                'nickname'      => $u->nickname,
                'username'      => $u->username,
                'avatar'        => $u->avatar,
                'verify_status' => $u->verify_status,
            ];
        }
    }

    foreach ($list as $item) {
        $uid = (int)$item->author_id;
        if (isset($authorMap[$uid])) {
            $u = $authorMap[$uid];

            $item->nickname    = $u['nickname'] ?: ($u['username'] ?? '匿名用户');
            $item->avatar      = $u['avatar'] ?: '';
            // 认证：cp_user.verify_status === 'approved' 视为已认证
            $item->is_verified = ($u['verify_status'] === 'approved') ? 1 : 0;
        } else {
            $item->nickname    = '匿名用户';
            $item->avatar      = '';
            $item->is_verified = 0;
        }
    }
    // ===== 补充作者信息结束 =====

    View::assign('list', $list);
    View::assign('page', $page);
    View::assign('totalPages', $totalPages);

    return View::fetch('help/index');
}


// 求助文章详情 + 评论 + 回复
public function detail($id)
{
    /** @var HelpModel|null $article */
    $article = HelpModel::find($id);
    if (!$article || $article->status !== 'online') {
        return '求助文章不存在或未发布';
    }

    // 作者信息（昵称、头像）
    $user = UserModel::find($article->author_id);
    if ($user) {
        $article->nickname = $user->nickname ?: $user->username;
        $article->avatar   = $user->avatar ?? '';
    }

    // 所有评论（顶级 + 回复）
    $comments = CommentModel::where('type', 'help')
        ->where('target_id', (int)$id)
        ->order('create_time', 'asc')
        ->select();

    // 一次性收集所有评论用户ID
    $userIds = [];
    foreach ($comments as $c) {
        if (!empty($c->user_id)) {
            $userIds[] = (int)$c->user_id;
        }
    }
    $userIds = array_unique(array_filter($userIds));

    // 批量查用户，带 verify_status
    $userMap = [];
    if (!empty($userIds)) {
        $users = UserModel::whereIn('id', $userIds)->select();
        foreach ($users as $u) {
            $userMap[(int)$u->id] = [
                'username'      => $u->username,
                'nickname'      => $u->nickname,
                'verify_status' => $u->verify_status,
            ];
        }
    }

    $topComments     = [];
    $repliesByParent = [];

    foreach ($comments as $c) {
        $info = $userMap[(int)$c->user_id] ?? null;
        if ($info) {
            $name       = $info['nickname'] ?: ($info['username'] ?? '匿名用户');
            $isVerified = ($info['verify_status'] === 'approved') ? 1 : 0;
        } else {
            $name       = '用户已不存在';
            $isVerified = 0;
        }

        $row = [
            'id'          => $c->id,
            'user_id'     => $c->user_id,
            'username'    => $name,
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

    View::assign('article', $article);
    View::assign('topComments', $topComments);
    View::assign('repliesByParent', $repliesByParent);

    return View::fetch('help/detail');
}


    /**
     * 编辑求助（新建 + 编辑）
     * 新建：/help/edit
     * 编辑：/help/edit?id=123
     */
    public function edit()
    {
        $userId = Session::get('user_id');
    $role   = Session::get('role');          // 新增：取角色
    if (!$userId) {
        return redirect('/user/login');
    }

    $id   = (int)Request::get('id', 0);
    $post = null;

    if ($id > 0) {
        $post = HelpModel::find($id);
        if (!$post) {
            return '求助不存在';
        }
        // 普通用户只能编辑自己的，管理员可以编辑所有
        if ($role !== 'admin' && (int)$post->author_id !== (int)$userId) {
            return '你没有权限编辑该求助';
        }
    }

    $p = $post ? $post->toArray() : [
        'id'      => 0,
        'title'   => '',
        'content' => '',
        'status'  => 'draft',
    ];

    View::assign('post', $p);
    return View::fetch('help/edit');
}

    /**
     * 保存求助（草稿 / 直接发布）
     */
    public function update()
    {
        $userId = Session::get('user_id');
    $role   = Session::get('role');
    if (!$userId) {
        return redirect('/user/login');
    }

    if (!Request::isPost()) {
        return redirect('/userCenter/posts');
    }

    $id      = (int)Request::post('id', 0);
    $title   = trim((string)Request::post('title', ''));
    $content = (string)Request::post('content', '');
    $action  = (string)Request::post('action', 'draft'); // draft / publish

    if ($title === '' || $content === '') {
        return '标题和内容不能为空';
    }

    if ($id > 0) {
        $post = HelpModel::find($id);
        if (!$post) {
            return '求助不存在';
        }
        // 普通用户只能改自己的，管理员可改所有
        if ($role !== 'admin' && (int)$post->author_id !== (int)$userId) {
            return '你没有权限编辑该求助';
        }
    } else {
        $post = new HelpModel();
        $post->author_id     = $userId;
        $post->create_time = date('Y-m-d H:i:s');
    }

    $post->title   = $title;
    $post->content = $content;

    if ($action === 'publish') {
        $post->status = 'online';
    } else {
        $post->status = 'draft';
    }

    $post->update_time = date('Y-m-d H:i:s');
    $post->save();

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

    /** @var HelpModel|null $post */
    $post = HelpModel::find($id);
    if (!$post) {
        return '求助不存在或已被删除';
    }

    // 权限：管理员可删全部，普通用户只能删自己发布的
    if ($role !== 'admin' && (int)$post->author_id !== (int)$userId) {
        return '你没有权限删除该求助';
    }

    $post->delete();

    // 删完回到“我的发布”
    return redirect('/userCenter/posts');
}


    // 富文本内容图片上传（求助）
    public function uploadContentImage()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return json(['errno' => 1, 'message' => '请先登录']);
        }

        if (!Request::isPost()) {
            return json(['errno' => 1, 'message' => '不支持的请求方式']);
        }

        // 前端配置的字段名
        $files = request()->file('wangeditor-uploaded-image');
        if (empty($files)) {
            return json(['errno' => 1, 'message' => '没有选择文件']);
        }

        $files = is_array($files) ? $files : [$files];

        $urls = [];
        try {
            foreach ($files as $file) {
                // 保存到 public/storage/help_content/xxx.jpg
                $saveName = Filesystem::disk('public')->putFile('help_content', $file);
                $urls[] = '/storage/' . $saveName;
            }
        } catch (\Throwable $e) {
            return json(['errno' => 1, 'message' => '上传失败：' . $e->getMessage()]);
        }

        // wangEditor v4 返回格式
        return json([
            'errno' => 0,
            'data'  => $urls,
        ]);
    }

    // 提交评论 / 回复
    public function reply()
    {
        $userId = Session::get('user_id');
        if (empty($userId)) {
            return redirect('/user/login')->with('error', '请先登录后再发表评论');
        }

        if (!Request::isPost()) {
            abort(405, '非法请求方式');
        }

        $helpId   = (int)Request::post('help_id', 0);
        $parentId = (int)Request::post('parent_id', 0);
        $content  = trim((string)Request::post('content', ''));

        if ($helpId <= 0 || $content === '') {
            return redirect('/help/detail?id=' . $helpId)->with('error', '评论内容不能为空');
        }

        $article = HelpModel::find($helpId);
        if (!$article || $article->status !== 'online') {
            return redirect('/help/index')->with('error', '求助文章不存在或未发布');
        }

        $comment              = new CommentModel();
        $comment->type        = 'help';
        $comment->target_id   = $helpId;
        $comment->parent_id   = $parentId;
        $comment->user_id     = $userId;
        $comment->content     = $content;
        $comment->create_time = date('Y-m-d H:i:s');
        $comment->save();

        return redirect('/help/detail?id=' . $helpId . '#reply');
    }
}
