<?php

/**
 * webdav 文件管理处理;
 */
class kodWebDav extends HttpDavServer {
	public function __construct($DAV_PRE) {
		$this->plugin = Action('webdavPlugin');
		//pr($_SERVER);exit;
		$this->checkUser();
		$this->initPath($DAV_PRE);
	}

	public function run(){
		$method = 'http'.HttpHeader::method();
		if(!method_exists($this,$method)){
			return HttpAuth::error();
		}
		$result = $this->$method();
		if(!$result) return;//文件下载;
		self::response($result);
    }

	/**
	 * 用户登陆校验;权限判断;
	 * 性能优化: 通过cookie处理为已登录; (避免ad域用户或用户集成每次进行登陆验证;)
	 */
	public function checkUser(){
		$userInfo = Session::get("kodUser");
	    if(!$userInfo || !is_array($userInfo)){
    	    $user = HttpAuth::get();
    		$find = ActionCall('user.index.userInfo', $user['user'],$user['pass']);
			$this->plugin->log(array($user,$find));
    		if ( !is_array($find) || !isset($find['userID']) ){
    			return HttpAuth::error();
    		}
    		ActionCall('user.index.loginSuccess',$find);
	    }
		if(!$this->plugin->authCheck()){
			self::response(array('code'=>404,'body'=>"<error>您没有此权限!</error>"));exit;
		}
	}
	public function parsePath($path){
		$options = $this->plugin->getConfig();
		$rootPath = '{block:files}/';
		if($options['pathAllow'] == 'self'){ //个人网盘
			$rootPath = MY_HOME;
		}
		if(!$path || $path == '/') return $rootPath;

		$pathArr = explode('/',KodIO::clear(trim($path,'/')));
		$rootList = Action('explorer.list')->path($rootPath);
		return $this->pathInfoDeep($rootList,$pathArr);
	}
	/**
	 * 向下回溯路径;
	 */
	private function pathInfoDeep($parent,$pathArr){
		$list = $this->pathListMerge($parent);
		$itemArr = array_to_keyvalue($list,'name');
		$item = $itemArr[$pathArr[0]];
		if(!$item) return false;
		if(count($pathArr) == 1) return $item['path'];
		
		$pathAppend = implode('/',array_slice($pathArr,1));
		$newPath = KodIO::clear($item['path'].'/'.$pathAppend);
		$info = IO::infoFull($newPath);
		// pr($newPath,$item,$pathArr,$info,count($parent['folderList']));
		if($info) return $info['path'];

		$parent = Action('explorer.list')->path($item['path']);
		$result  = $this->pathInfoDeep($parent,array_slice($pathArr,1));
		if(!$result){
			$result = $newPath;
			//虚拟目录追; 没找到字内容;则认为不存在;
			if(Action('explorer.auth')->pathOnlyShow($item['path']) ){
				$result = false;
			}
		}
		return $result;
	}
	
	public function can($path,$action){
		$result = Action('explorer.auth')->fileCan($path,$action);
		// 编辑;则检测当前存储空间使用情况;
		if($result && $action == 'edit'){
			$result = Action('explorer.auth')->spaceAllow($path);
		}
		return $result;
	}
	public function pathExists($path){
		$info = IO::infoFull($path);
		if(!$info) return false;
		if($info['isDelete'] == '1') return false;
		return true;
	}
	
	/**
	 * 文档属性及列表;
	 * 不存在:404;存在207;  文件--该文件属性item; 文件夹--该文件属性item + 多个子内容属性
	 */
	public function pathList($path){
		if(!$path) return false;
		$info   = IO::infoFull($path);
		if(!$info && !Action('explorer.auth')->pathOnlyShow($path) ){
			return false;
		}

		if(!$this->can($path,'show')) return false;
		if($info && $info['isDelete'] == '1') return false;//回收站中;
		if($info && $info['type'] == 'file'){ //单个文件;
			return array('fileList'=>array($info));
		}
		
		$pathParse = KodIO::parse($path);
		// 分页大小处理--不分页; 搜索结果除外;
		if($pathParse['type'] != KodIO::KOD_SEARCH){
			$GLOBALS['in']['pageNum'] = -1;
		}
		// write_log([$path,$pathParse,$GLOBALS['in']],'test');		
		return Action('explorer.list')->path($path);
	}
	
	public function pathMkdir($path){
		if(!$path){ //收藏夹下的文件夹;
			$inPath  = $this->pathGet();
			$path = $this->parsePath(IO::pathFather($inPath)).'/'.IO::pathThis($inPath);
		}
		if(!$this->can($path,'edit')) return false;
		return IO::mkdir($path);
	}
	public function pathOut($path){
		if(!$this->pathExists($path) || !$this->can($path,'view')){
			self::response(array('code' => 404));exit;
		}
		if(IO::size($path)<=0) return;//空文件处理;
		//部分webdav客户端不支持301跳转;
		if($this->notSupportHeader()){
			IO::fileOutServer($path); 
		}else{
			IO::fileOut($path); 
		}
	}
	// GET 下载文件;是否支持301跳转;对象存储下载走直连;
	private function notSupportHeader(){
		$software = array(
			'ReaddleDAV Documents',	//ios Documents 不支持;
		);
		$ua = $_SERVER['HTTP_USER_AGENT'];
		foreach ($software as $type){
			if(stristr($ua,$type)) return true;
		}
		return false;
	}	
	
	public function pathPut($path,$localFile=''){
		if(!$path){ //收藏夹下的文件夹;
			$inPath  = $this->pathGet();
			$path = $this->parsePath(IO::pathFather($inPath)).'/'.IO::pathThis($inPath);
		}
		$info = IO::infoFull($path);
		if($info){	// 文件已存在; 则使用文件父目录追加文件名;
			$name 		= IO::pathThis($this->pathGet());
			$father 	= IO::init($path)->getPathOuter(IO::pathFather($path));
			$uploadPath = rtrim($father,'/').'/'.$name; //构建上层目录追加文件名;
		}else{		// 首次请求创建,文件不存在; 则使用{source:xx}/newfile.txt;
			$uploadPath = $path;
		}
		if(!$this->can($path,'edit')) return false;

		// 传入了文件; wscp等直接一次上传处理的情况;  windows/mac等会调用锁定,解锁,判断是否存在等之后再上传;
		// 文件夹下已存在,或在回收站中处理;
		$replace = REPEAT_REPLACE;
		if($localFile){
			$result = IO::upload($uploadPath,$localFile,true,$replace);
		}else{
			$result = IO::mkfile($uploadPath,'',$replace);
		}

		// 删除临时文件; mac系统生成两次 ._file.txt;
		if($localFile){ 
			$this->pathPutRemoveTemp($uploadPath);
			$this->pathPutRemoveTemp($uploadPath);
		}
		$this->plugin->log("upload=$uploadPath;path=$path;res=$result;local=$localFile;");
		return $result;
	}
	private function pathPutRemoveTemp($path){
		$pathArr = explode('/',$path);
		$pathArr[count($pathArr) - 1] = '._'.$pathArr[count($pathArr) - 1];
		
		$tempPath = implode('/',$pathArr);
		$tempInfo = IO::infoFull($tempPath);
		if($tempInfo){
			IO::remove($tempInfo['path'],false);
		}
	}
	
	public function pathRemove($path){
		if(!$this->can($path,'remove')) return false;
		return IO::remove($path);
	}
	public function pathMove($path,$dest){
		$pathUrl = $this->pathGet();
		$destURL = $this->pathGet(true);
		$this->plugin->log("move from=$path;to=$dest;$pathUrl;$destURL");

		// 目录不变,重命名
		$io = IO::init('/');
		if($io->pathFather($pathUrl) == $io->pathFather($destURL)){
			if(!$this->can($path,'edit')) return false;
			$this->plugin->log("move edit=$path;$pathUrl;$destURL;dest=".intval($this->pathExists($dest)));
			$fromExt = get_path_ext($pathUrl);
			$toExt   = get_path_ext($destURL);
			$officeExt = array('doc','docx','xls','xlsx','ppt','pptx');
			/**
			 * office 编辑保存最后落地时处理（导致历史记录丢失）； 
			 * 0. 上传~tmp1601041332501525796.TMP //锁定,上传,解锁;
			 * 1. 移动 test.docx => test~388C66.tmp 				// 改造,识别到之后不进行移动重命名;
			 * 2. 移动 ~tmp1601041332501525796.TMP => test.docx; 	// 改造;目标文件已存在则更新文件;删除原文件;
			 * 3. 删除 test~388C66.tmp  
			 */
			if( $this->isWindows() && $toExt == 'tmp' && in_array($fromExt,$officeExt) ){
				$result =  IO::mkfile($dest);
			    $this->plugin->log("move mkfile=$path;$pathUrl;$destURL;res=".$result);
			    return $result;
			}
			// 都存在则覆盖；
			if( $this->pathExists($path) && $this->pathExists($dest) ){
				$result = IO::saveFile($path,$dest);
				$this->plugin->log("move saveFile=$path;res=".$dest.';res='.$result);
				return $result;
			}			
			return IO::rename($path,$io->pathThis($destURL));
		}
		
		// 移动到目标文件夹;多一层当前文件名时则去除;
		if(!$this->pathExists($dest)){
			$info = IO::infoFull($io->pathFather($dest));
			if(!$info) return false;
			$dest = $info['path'];
		}
		if(!$this->can($path,'remove')) return false;
		if(!$this->can($dest,'edit')) return false;
		return IO::move($path,$dest);
	}
	public function pathCopy($path,$dest){
		if(!$this->can($path,'download')) return false;
		if(!$this->can($dest,'edit')) return false;
		return IO::copy($path,$dest);
	}
	private function isWindows(){
	    return stristr($_SERVER['HTTP_USER_AGENT'],'Microsoft-WebDAV-MiniRedir');
	}
}