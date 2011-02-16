<?php
		
	// Creates a small random string for validating moderators' cookies
	function mkhash($length=12) {
		// The method here isn't really important,
		// but I think this generates a relatively
		// unique string that looks cool.
		// If you choose to change this, make sure it cannot include a ':' character.
		return substr(base64_encode(sha1(rand() . time(), true)), 0, $length);
	}
	
	function login($username, $password, $makehash=true) {
		global $mod;
		
		// SHA1 password
		if($makehash) {
			$password = sha1($password);
		}
		
		$query = prepare("SELECT `id`,`type` FROM `mods` WHERE `username` = :username AND `password` = :password LIMIT 1");
		$query->bindValue(':username', $username);
		$query->bindValue(':password', $password);
		$query->execute() or error(db_error($query));
		
		if($user = $query->fetch()) {
			return $mod = Array(
				'id' => $user['id'],
				'type' => $user['type'],
				'username' => $username,
				'password' => $password,
				'hash' => isset($_SESSION['mod']['hash']) ? $_SESSION['mod']['hash'] : mkhash()
				);
		} else return false;
	}
	
	function setCookies() {
		global $mod, $config;
		if(!$mod) error('setCookies() was called for a non-moderator!');
		
		// $config['cookies']['mod'] contains username:hash
		setcookie($config['cookies']['mod'], $mod['username'] . ':' . $mod['hash'], time()+$config['cookies']['expire'], $config['cookies']['jail']?$config['root']:'/', null, false, true);
		
		// Put $mod in the session
		$_SESSION['mod'] = $mod;
		
		// Lock sessions to IP addresses
		if($mod['lock_ip'])
			$_SESSION['mod']['ip'] = $_SERVER['REMOTE_ADDR'];
	}
	
	function destroyCookies() {
		// Delete the cookies
		setcookie($config['cookies']['mod'], 'deleted', time()-$config['cookies']['expire'], $config['cookies']['jail']?$config['root']:'/', null, false, true);
		
		// Unset the session
		unset($_SESSION['mod']);
	}
	
	function modLog($action) {
		global $mod;
		$query = prepare("INSERT INTO `modlogs` VALUES (:id, :ip, :time, :text)");
		$query->bindValue(':id', $mod['id'], PDO::PARAM_INT);
		$query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
		$query->bindValue(':time', time(), PDO::PARAM_INT);
		$query->bindValue(':text', $action);
		$query->execute() or error(db_error($query));
	}
	
	if(isset($_COOKIE['mod']) && isset($_SESSION['mod']) && is_array($_SESSION['mod'])) {
		// Should be username:session hash
		$cookie = explode(':', $_COOKIE['mod']);
		if(count($cookie) != 2) {
			destroyCookies();
			error($config['error']['malformed']);
		}
		
		// Validate session
		if(	$cookie[0] != $_SESSION['mod']['username'] ||
			$cookie[1] != $_SESSION['mod']['hash']) {
			// Malformed cookies
			destroyCookies();
			error($config['error']['malformed']);
		}
		
		// Open connection
		sql_open();
		
		// Check username/password
		if(!login($_SESSION['mod']['username'], $_SESSION['mod']['password'], false)) {
			destroyCookies();
			error($config['error']['invalidafter']);
		}
		
	}
	
	// Generates a <ul> element with a list of linked
	// boards and their subtitles. (without the <ul> opening and ending tags)
	function ulBoards() {
		global $mod, $config;
		
		$body = '';
		
		// List of boards
		$boards = listBoards();
		
		foreach($boards as &$b) {
			$body .= '<li>' . 
				'<a href="?/' .
						sprintf($config['board_path'], $b['uri']) . $config['file_index'] .
						'">' .
					sprintf($config['board_abbreviation'], $b['uri']) .
					'</a> - ' .
					$b['title'] .
					(isset($b['subtitle']) ? '<span class="unimportant"> — ' . $b['subtitle'] . '</span>' : '') . 
				'</li>';
		}
		
		if($mod['type'] >= $config['mod']['newboard']) {
			$body .= '<li style="margin-top:15px;"><a href="?/new"><strong>Create new board</strong></a></li>';
		}
		return $body;
	}
	
	function form_newBan($ip=null, $reason='', $continue=false, $delete=false, $board=false) {
		return '<fieldset><legend>New ban</legend>' . 
					'<form action="?/ban" method="post">' . 
						($continue ? '<input type="hidden" name="continue" value="' . htmlentities($continue) . '" />' : '') .
						($delete ? '<input type="hidden" name="delete" value="' . htmlentities($delete) . '" />' : '') .
						($board ? '<input type="hidden" name="board" value="' . htmlentities($board) . '" />' : '') .
						'<table>' .
						'<tr>' . 
							'<th><label for="ip">IP</label></th>' .
							'<td><input type="text" name="ip" id="ip" size="15" maxlength="15" ' . 
								(isset($ip) ?
									'value="' . htmlentities($ip) . '" ' : ''
								) .
							'/></td>' .
						'</tr>' . 
						'<tr>' . 
							'<th><label for="reason">Reason</label></th>' .
							'<td><textarea name="reason" id="reason" rows="5" cols="30">' .
								htmlentities($reason) .
							'</textarea></td>' .
						'</tr>' . 
						'<tr>' . 
							'<th><label for="length">Length</label></th>' .
							'<td><input type="text" name="length" id="length" size="20" maxlength="40" />' .
							' <span class="unimportant">(eg. "2d1h30m" or "2 days")</span></td>' .
						'</tr>' . 
						'<tr>' . 
							'<td></td>' . 
							'<td><input name="new_ban" type="submit" value="New Ban" /></td>' . 
						'</tr>' . 
						'</table>' .
					'</form>' .
				'</fieldset>';
	}
	
	function form_newBoard() {
		return '<fieldset><legend>New board</legend>' . 
					'<form action="?/new" method="post">' . 
						'<table>' .
						'<tr>' . 
							'<th><label for="board">URI</label></th>' .
							'<td><input type="text" name="uri" id="board" size="3" maxlength="8" />' .
							' <span class="unimportant">(eg. "b"; "mu")</span></td>' .
						'</tr>' . 
						'<tr>' . 
							'<th><label for="title">Title</label></th>' .
							'<td><input type="text" name="title" id="title" size="15" maxlength="20" />' .
							' <span class="unimportant">(eg. "Random")</span></td>' .
						'</tr>' . 
						'<tr>' . 
							'<th><label for="subtitle">Subtitle</label></th>' .
							'<td><input type="text" name="subtitle" id="subtitle" size="20" maxlength="40" />' .
							' <span class="unimportant">(optional)</span></td>' .
						'</tr>' . 
						'<tr>' . 
							'<td></td>' . 
							'<td><input name="new_board" type="submit" value="New Board" /></td>' . 
						'</tr>' . 
						'</table>' .
					'</form>' .
				'</fieldset>';
	}

?>