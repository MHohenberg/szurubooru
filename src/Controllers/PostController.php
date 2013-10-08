<?php
class PostController
{
	public function workWrapper($callback)
	{
		$this->context->stylesheets []= 'jquery.tagit.css';
		$this->context->scripts []= 'jquery.tagit.js';
		$callback();
	}



	/**
	* @route /posts
	* @route /posts/{query}
	* @validate query .*
	*/
	public function listAction($query = null)
	{
		#redirect requests in form of /posts/?query=... to canonical address
		$formQuery = InputHelper::get('query');
		if (!empty($formQuery))
		{
			$url = \Chibi\UrlHelper::route('post', 'list', ['query' => $formQuery]);
			\Chibi\UrlHelper::forward($url);
			return;
		}

		$this->context->subTitle = 'browsing posts';
		$this->context->searchQuery = $query;

		PrivilegesHelper::confirmWithException($this->context->user, Privilege::ListPosts);

		$page = 1;
		$params = [];

		$allowedSafety = array_filter(PostSafety::getAll(), function($safety)
		{
			return PrivilegesHelper::confirm($this->context->user, Privilege::ListPosts, PostSafety::toString($safety));
		});
		//todo safety [user choice]

		$whereSql = 'WHERE safety IN (' . R::genSlots($allowedSafety) . ')';
		$params = array_merge($params, $allowedSafety);

		//todo construct WHERE based on filters

		//todo construct ORDER based on filers
		$orderSql = 'ORDER BY upload_date DESC';

		$limitSql = 'LIMIT ? OFFSET ?';
		$postsPerPage = intval($this->config->browsing->postsPerPage);
		$params[] = $postsPerPage;
		$params[] = ($page - 1) * $postsPerPage;

		$posts = R::findAll('post', sprintf('%s %s %s', $whereSql, $orderSql, $limitSql), $params);
		$this->context->transport->posts = $posts;
	}



	/**
	* @route /post/upload
	*/
	public function uploadAction()
	{
		$this->context->stylesheets []= 'upload.css';
		$this->context->scripts []= 'upload.js';
		$this->context->subTitle = 'upload';

		PrivilegesHelper::confirmWithException($this->context->user, Privilege::UploadPost);

		if (isset($_FILES['file']))
		{
			$suppliedSafety = intval(InputHelper::get('safety'));
			if (!in_array($suppliedSafety, PostSafety::getAll()))
				throw new SimpleException('Invalid safety type "' . $suppliedSafety . '"');

			$suppliedTags = InputHelper::get('tags');
			$suppliedTags = preg_split('/[,;\s+]/', $suppliedTags);
			$suppliedTags = array_filter($suppliedTags);
			$suppliedTags = array_unique($suppliedTags);
			foreach ($suppliedTags as $tag)
				if (!preg_match('/^[a-zA-Z0-9_-]+$/i', $tag))
					throw new SimpleException('Invalid tag "' . $tag . '"');

			$suppliedFile = $_FILES['file'];

			switch ($suppliedFile['type'])
			{
				case 'image/gif':
				case 'image/png':
				case 'image/jpeg':
					$postType = PostType::Image;
					break;
				case 'application/x-shockwave-flash':
					$postType = PostType::Flash;
					break;
				default:
					throw new SimpleException('Invalid file type "' . $suppliedFile['type'] . '"');
			}

			//todo: find out duplicate files

			do
			{
				$name = md5(mt_rand() . uniqid());
				$path = $this->config->main->filesPath . DIRECTORY_SEPARATOR . $name;
			}
			while (file_exists($path));

			$dbTags = [];
			foreach ($suppliedTags as $tag)
			{
				$dbTag = R::findOne('tag', 'name = ?', [$tag]);
				if (!$dbTag)
				{
					$dbTag = R::dispense('tag');
					$dbTag->name = $tag;
					R::store($dbTag);
				}
				$dbTags []= $dbTag;
			}

			$dbPost = R::dispense('post');
			$dbPost->type = $postType;
			$dbPost->name = $name;
			$dbPost->mime_type = $suppliedFile['type'];
			$dbPost->safety = $suppliedSafety;
			$dbPost->upload_date = time();
			$dbPost->sharedTag = $dbTags;
			$dbPost->ownUser = $this->context->user;

			move_uploaded_file($suppliedFile['tmp_name'], $path);
			R::store($dbPost);

			$this->context->transport->success = true;
		}
	}



	/**
	* Action that decorates the page containing the post.
	* @route /post/{id}
	*/
	public function viewAction($id)
	{
		$post = R::findOne('post', 'id = ?', [$id]);
		if (!$post)
			throw new SimpleException('Invalid post ID "' . $id . '"');

		PrivilegesHelper::confirmWithException($this->context->user, Privilege::ViewPost);
		PrivilegesHelper::confirmWithException($this->context->user, Privilege::ViewPost, PostSafety::toString($post->safety));

		$this->context->subTitle = 'showing @' . $post->id;
		$this->context->transport->post = $post;
	}



	/**
	* Action that renders the thumbnail of the requested file and sends it to user.
	* @route /post/thumb/{id}
	*/
	public function thumbAction($id)
	{
		$this->context->layoutName = 'layout-file';

		$post = R::findOne('post', 'id = ?', [$id]);
		if (!$post)
			throw new SimpleException('Invalid post ID "' . $id . '"');

		PrivilegesHelper::confirmWithException($this->context->user, Privilege::ViewPost);
		PrivilegesHelper::confirmWithException($this->context->user, Privilege::ViewPost, PostSafety::toString($post->safety));

		$path = $this->config->main->thumbsPath . DIRECTORY_SEPARATOR . $post->name . '.png';
		if (!file_exists($path))
		{
			$srcPath = $this->config->main->thumbsPath . DIRECTORY_SEPARATOR . $post->name;
			$dstPath = $path;
			$dstWidth = $this->config->browsing->thumbWidth;
			$dstHeight = $this->config->browsing->thumbHeight;

			switch($post->mime_type)
			{
				case 'image/jpeg':
					$srcImage = imagecreatefromjpeg($srcPath);
					break;
				case 'image/png':
					$srcImage = imagecreatefrompng($srcPath);
					break;
				case 'image/gif':
					$srcImage = imagecreatefromgif($srcPath);
					break;
				case 'application/x-shockwave-flash':
					$path = $this->config->main->mediaPath . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'thumb-swf.png';
					break;
				default:
					$path = $this->config->main->mediaPath . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'thumb.png';
					break;
			}

			if (isset($srcImage))
			{
				switch ($this->config->browsing->thumbStyle)
				{
					case 'outside':
						$dstImage = ThumbnailHelper::cropOutside($srcImage, $dstWidth, $dstHeight);
						break;
					case 'inside':
						$dstImage = ThumbnailHelper::cropInside($srcImage, $dstWidth, $dstHeight);
						break;
					default:
						throw new SimpleException('Unknown thumbnail crop style');
				}

				imagepng($dstImage, $dstPath);
				imagedestroy($srcImage);
				imagedestroy($dstImage);
			}
		}
		if (!is_readable($path))
			throw new SimpleException('Thumbnail file is not readable');

		\Chibi\HeadersHelper::set('Pragma', 'public');
		\Chibi\HeadersHelper::set('Cache-Control', 'max-age=86400');
		\Chibi\HeadersHelper::set('Expires', gmdate('D, d M Y H:i:s \G\M\T', time() + 86400));

		$this->context->transport->mimeType = 'image/png';
		$this->context->transport->filePath = $path;
	}



	/**
	* Action that renders the requested file itself and sends it to user.
	* @route /post/retrieve/{name}
	*/
	public function retrieveAction($name)
	{
		$this->context->layoutName = 'layout-file';

		$post = R::findOne('post', 'name = ?', [$name]);
		if (!$post)
			throw new SimpleException('Invalid post name "' . $name . '"');

		PrivilegesHelper::confirmWithException($this->context->user, Privilege::RetrievePost);
		PrivilegesHelper::confirmWithException($this->context->user, Privilege::RetrievePost, PostSafety::toString($post->safety));

		$path = $this->config->main->filesPath . DIRECTORY_SEPARATOR . $post->name;
		if (!file_exists($path))
			throw new SimpleException('Post file does not exist');
		if (!is_readable($path))
			throw new SimpleException('Post file is not readable');

		$this->context->transport->mimeType = $post->mimeType;
		$this->context->transport->filePath = $path;
	}



	/**
	* @route /favorites
	*/
	public function favoritesAction()
	{
		$this->listAction('favmin:1');
		$this->context->viewName = 'post-list';
	}
}
