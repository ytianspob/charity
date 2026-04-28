<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\News as NewsModel;
use app\model\Comment as CommentModel;
use app\model\User as UserModel;
use think\facade\Request;
use think\facade\Session;
use think\facade\View;
use think\facade\Filesystem;

class News extends BaseController
{
    // 新闻列表（支持分类筛选 + 分页，每页5条）
    public function index()
    {
        $category = Request::get('category', ''); // case / notice / other / 空表示全部
        $page     = (int)Request::get('page', 1);
        if ($page < 1) {
            $page = 1;
        }

        $query = NewsModel::where('status', 'online')
            ->order('create_time', 'desc');

        if ($category !== '') {
            $query->where('category', $category);
        }

        // 每页 5 条
        $pageSize = 5;
        $list     = $query->page($page, $pageSize)->select();
        $total    = $query->count(); // 注意：ThinkPHP 查询构造器会重用条件

        $totalPages = (int)ceil($total / $pageSize);

        View::assign('list', $list);
        View::assign('category', $category);
        View::assign('page', $page);
        View::assign('totalPages', $totalPages);

        return View::fetch('news/index');
    }

// 新闻详情 + 评论 + 回复
public function detail($id)
{
    /** @var NewsModel|null $news */
    $news = NewsModel::find($id);
    if (!$news || $news->status !== 'online') {
        return '新闻不存在或未发布';
    }

    // 所有评论（顶级 + 回复）
    $comments = CommentModel::where('type', 'news')
        ->where('target_id', (int)$id)
        ->order('create_time', 'asc')
        ->select();

    // 收集评论涉及到的用户ID
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

    View::assign('news', $news);
    View::assign('topComments', $topComments);
    View::assign('repliesByParent', $repliesByParent);

    return View::fetch('news/detail');
}

public function add()
{
    $userId = Session::get('user_id');
    if (!$userId) {
        return redirect('/user/login');
    }

    if (!Request::isPost()) {
        return redirect('/');
    }

    $type      = Request::post('type');      // 'news' / 'project' / 'help'
    $targetId  = (int)Request::post('target_id');
    $parentId  = (int)Request::post('parent_id', 0);
    $content   = trim((string)Request::post('content', ''));

    if ($content === '') {
        return '评论内容不能为空';
    }

    CommentModel::create([
        'type'       => $type,
        'target_id'  => $targetId,
        'parent_id'  => $parentId,
        'user_id'    => $userId,
        'content'    => $content,
        'create_time'=> date('Y-m-d H:i:s'),
    ]);

    // 根据类型重定向回对应详情页
    if ($type === 'news') {
        return redirect('/news/detail?id=' . $targetId);
    } elseif ($type === 'project') {
        return redirect('/project/detail?id=' . $targetId);
    } elseif ($type === 'help') {
        return redirect('/help/detail?id=' . $targetId);
    }

    return redirect('/');
}

// 新闻编辑（新建 + 编辑）
    public function edit()
    {
        $userId = Session::get('user_id');
        $role   = Session::get('role');
        if (!$userId || $role !== 'admin') {
            return redirect('/user/login');
        }

        $id = (int)Request::get('id', 0);
        $news = null;

        if ($id > 0) {
            $news = NewsModel::find($id);
            if (!$news) {
                return '新闻不存在';
            }
        }

        $n = $news ? $news->toArray() : [
            'id'       => 0,
            'title'    => '',
            'category' => 'other',   // 默认其他
            'summary'  => '',
            'author'   => '',
            'cover'    => '',
            'content'  => '',
            'status'   => 'draft',
        ];

        View::assign('news', $n);
        return View::fetch('news/edit');
    }

    // 保存新闻（草稿 / 直接发布）
    public function update()
    {
        $userId = Session::get('user_id');
        $role   = Session::get('role');
        if (!$userId || $role !== 'admin') {
            return redirect('/user/login');
        }

        if (!Request::isPost()) {
            return redirect('/adminCenter/newsList');
        }

        $id       = (int)Request::post('id', 0);
        $title    = trim((string)Request::post('title', ''));
        $category = trim((string)Request::post('category', 'other'));
        $summary  = trim((string)Request::post('summary', ''));
        $author   = trim((string)Request::post('author', ''));
        $cover    = trim((string)Request::post('cover', ''));
        $content  = (string)Request::post('content', '');
        $action   = (string)Request::post('action', 'draft');   // draft / publish

        if ($title === '' || $content === '') {
            return '标题和内容不能为空';
        }

        if (!in_array($category, ['case','notice','other'], true)) {
            $category = 'other';
        }

        if ($id > 0) {
            $news = NewsModel::find($id);
            if (!$news) {
                return '新闻不存在';
            }
        } else {
            $news = new NewsModel();
            $news->create_time = date('Y-m-d H:i:s');
        }

        $news->title    = $title;
        $news->category = $category;
        $news->summary  = $summary;
        $news->author   = $author;
        $news->cover    = $cover;
        $news->content  = $content;

        if ($action === 'publish') {
            $news->status = 'online';   // 直接发布，无需审核
        } else {
            $news->status = 'draft';
        }

        $news->update_time = date('Y-m-d H:i:s');
        $news->save();

        return redirect('/adminCenter/newsList');
    }

    // 富文本内容图片上传
    public function uploadContentImage()
    {
        $userId = Session::get('user_id');
        $role   = Session::get('role');
        if (!$userId || $role !== 'admin') {
            return json(['errno' => 1, 'message' => '请先登录管理员账号']);
        }

        if (!Request::isPost()) {
            return json(['errno' => 1, 'message' => '不支持的请求方式']);
        }

        $files = request()->file('wangeditor-uploaded-image');
        if (empty($files)) {
            return json(['errno' => 1, 'message' => '没有选择文件']);
        }

        $files = is_array($files) ? $files : [$files];
        $urls  = [];

        try {
            foreach ($files as $file) {
                $saveName = Filesystem::disk('public')->putFile('news_content', $file);
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

    // 上传封面
    public function uploadCover()
    {
        $userId = Session::get('user_id');
        $role   = Session::get('role');
        if (!$userId || $role !== 'admin') {
            return json(['code' => 0, 'msg' => '请先登录管理员账号']);
        }

        if (!Request::isPost()) {
            return json(['code' => 0, 'msg' => '不支持的请求方式']);
        }

        $file = Request::file('cover');
        if (!$file) {
            return json(['code' => 0, 'msg' => '没有选择文件']);
        }

        try {
            $saveName = Filesystem::disk('public')->putFile('news_cover', $file);
            $url      = '/storage/' . $saveName;

            return json(['code' => 1, 'msg' => '上传成功', 'url' => $url]);
        } catch (\Throwable $e) {
            return json(['code' => 0, 'msg' => '上传失败：' . $e->getMessage()]);
        }
    }
}
