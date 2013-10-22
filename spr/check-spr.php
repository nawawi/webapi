<?php
/**
  * check-spr - fetch data from spr website 
  * Copyright (C) 2012-2013 Mohd Nawawi Mohamad Jamili <nawawi@rutweb.com>,<mohd.nawawi@gmail.com>
  *
  * This file is distributed under the terms of the GNU General Public
  * License (GPL). Copies of the GPL can be obtained from:
  * http://www.gnu.org/licenses/gpl.html 
  */

function _null($str) {
        if ( @is_null($str) || "$str"=="" ) return true;
        return false;
}

function _num($num) {
	return @preg_match("/^\d+$/",$num);
}

function _array($array) {
        if ( @is_array($array) && !empty($array) ) return true;
        return false;
}

function _is_serialized( $data ) {
	// if it isn't a string, it isn't serialized
	if ( !is_string( $data ) )
		return false;
	$data = trim( $data );
	if ( 'N;' == $data )
		return true;
	if ( !preg_match( '/^([adObis]):/', $data, $badions ) )
		return false;
	switch ( $badions[1] ) {
		case 'a' :
		case 'O' :
		case 's' :
			if ( preg_match( "/^{$badions[1]}:[0-9]+:.*[;}]\$/s", $data ) )
				return true;
			break;
		case 'b' :
		case 'i' :
		case 'd' :
			if ( preg_match( "/^{$badions[1]}:[0-9.E-]+;\$/", $data ) )
				return true;
			break;
	}
	return false;
}

function _mkdir($path, $mode = 0755, $recursive = true) {
	$umask = umask(0);
	$ret = @mkdir($path, $mode, $recursive);
	umask($umask);
	return $ret;
}

function _exit($msg) {
	if ( is_array($msg) && !empty($msg) ) {
		exit(json_encode($msg));
	}
	exit($msg);
}

function _file_put($filename, $text, $append=false, $chmod=0600, $compress = false) {
	$dirname = dirname($filename);

	if ( @is_array($text) || @is_object($text) ) {
		if ( _array($text) || _object($text) ) {
			$text=serialize($text);
		} else {
			$text="";
		}
	}

	$filenamec = $filename;
	if ( $compress ) {
		$filenamec = "compress.zlib://{$filename}";
	}

	$stat = false;
	if ( $append ) {
		$stat = @file_put_contents($filenamec,$text,FILE_APPEND);
	} else {
		if ( !_null($dirname) && !file_exists($dirname) ) {
			_mkdir($dirname,0700,true);
		}
		if ( $compress ) {
			$stat = @file_put_contents($filenamec, $text);
		} else {
			$stat = @file_put_contents($filenamec, $text, LOCK_EX);
		}
	}
	if ( $stat ) {
		@chmod($filename, $chmod);
		return true;
	}
	return false;
}

function _file_get($file) {
	clearstatcache();
	if ( file_exists($file) ) {
		$buff = trim(@file_get_contents("compress.zlib://$file"));
		if ( _is_serialized($buff) ) {
			$buff = unserialize($buff);
			if ( _array($buff) ) {
				if ( !_null($buff['nwobject']) ) {
					unset($buff['nwobject']);
					$buff = (object)$buff;
				}
				return $buff;
			}
		}
		return $buff;
	}
	return null;
}

function _getuserip() {
	if ( !_array($_SERVER) ) return null;
	$_userip = null;

	// 18/09/2011 - cloudflare
	if ( !_null($_SERVER['HTTP_CF_CONNECTING_IP']) ) {
		$_userip = $_SERVER['HTTP_CF_CONNECTING_IP'];
	} elseif ( !_null($_SERVER['HTTP_X_FORWARDED_FOR']) ) {
		$_userip = $_SERVER['HTTP_X_FORWARDED_FOR'];
	} elseif ( !_null($_SERVER['HTTP_CLIENT_IP']) ) {
		$_userip = $_SERVER['HTTP_CLIENT_IP']; 
	} else {
		$_userip = $_SERVER['REMOTE_ADDR']; 
	}
	if ( preg_match("/,\s(\S+)$/", $_userip, $mm) ) {
		$_userip = $mm[1];
	}
	return ( !_null($_userip) ? trim($_userip) : null );
}

function _getuseragent() {
	return ( !_null($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null);
}

function _output($status, $msg, $extra = null) {
    $data = array("success"=> $status, "msg" => $msg);
    if ( _array($extra) ) {
        $data = array_merge($data, $extra);
    }
    header("Content-type: application/json");
    _exit($data);
}

class spr {
    private $url = "http://daftarj.spr.gov.my/DaftarjBM.aspx";
    //private $url = "http://daftarj.spr.gov.my/semakpru13.aspx";
	private $uagent = "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.11 (KHTML, like Gecko) Chrome/23.0.1271.64 Safari/537.11";
    private $key1 = null;
    private $key2 = null;
    private $ic = null;
    private $cache_path = null;
    private $log_path = null;
    private $request = null;
    public $cache_timeout = 43200; // minutes

	public function __construct() {
        $this->request = array_merge($_GET, $_POST);
        $this->ic = $this->request['ic'];
 
        if ( !_null($_ENV['OPENSHIFT_DATA_DIR']) ) {
            $this->cache_path = $_ENV['OPENSHIFT_DATA_DIR']."/spr/cache";
            if ( !is_dir($this->cache_path) ) {
                _mkdir($this->cache_path, 0777);
            }
            $this->log_path = $_ENV['OPENSHIFT_DATA_DIR']."/spr/logs";
            if ( !is_dir($this->log_path) ) {
                _mkdir($this->log_path, 0777);
            }
        }
        $this->logs($this->ic);
    }

	public function __destruct() { 
		return true;
	}

    private function logs($txt) {
        $uag = _getuseragent();
        $uip = _getuserip();
        $str = "UA: \"{$uag}\", UIP: {$uip}, IC: \"{$txt}\"\n";
        $fi = $this->log_path."/".date('dmY').".log";
        _file_put($fi,$str,true,0666);
    }

    private function cache_save($key,$data) {
        if ( is_dir($this->cache_path) && $this->cache_path != "." ) {
            _file_put($this->cache_path."/ic-".$key, $data, false, 0666);
        }
    }

    private function cache_get($key) {
        $file = $this->cache_path."/ic-".$key;
        if ( file_exists($file) ) {
			clearstatcache();
			if ( file_exists($file) && _num($this->cache_timeout) && (time() - filemtime($file)) > ($this->cache_timeout*60) ) {
				@unlink($file);
            } else {
                return _file_get($file);
            }
        }
        return null;
    }

    private function getkey() {
        $data_post = array(
                "http" => array( 
                    "method" => "GET",
                    "user_agent" => $this->uagent,
                    "timeout" => 15
                ), 
            );
        $context = stream_context_create($data_post);
        $result = @file_get_contents($this->url, false, $context);

        if ( !_null($result) ) {
            if ( preg_match("/__VIEWSTATE\" value=\"(.*?)\"/", $result, $mm) ) {
		        $this->key1 = $mm[1];
	        }
	        if ( preg_match("/__EVENTVALIDATION\" value=\"(.*?)\"/", $result, $mm) ) {
		        $this->key2 = $mm[1];
            }
        }
    }

    public function output() {
   
        if ( _null($this->ic) ) {
            _output(false,"Invalid parameter");
        }

        $this->ic = preg_replace("/\-/","", $this->ic);
	/*if ( _getuseragent() == "Mozilla/5.0") {
		$this->logs($this->ic." DROP");

            _output(false,"");
	}*/
 
        if ( _null($this->ic) ) {
            _output(false,"Invalid parameter");
        }

        // cache
        $output = $this->cache_get($this->ic);
        if ( _array($output) ) {
            _output(true, "Fetch OK", $output);
        }

        $this->getkey();

        if ( _null($this->key1) || _null($this->key2) ) {
            _output(false, "Failed to fetch key");
        }

        $post = "__EVENTTARGET=&__EVENTARGUMENT&__VIEWSTATE=".rawurlencode($this->key1);
        $post .= "&__EVENTVALIDATION=".rawurlencode($this->key2)."&txtIC=".$this->ic."&Semak=Semak";	
        $data_post = array(
                "http" => array( 
                    "method" => "POST",
                    "header" => "Content-type: application/x-www-form-urlencoded\r\n", 
                    "user_agent" => $this->uagent,
                    "content" => $post, 
                    "timeout" => 15
                ), 
            );

        $context = stream_context_create($data_post);
        $result = @file_get_contents($this->url, false, $context);

        if ( !$result ) {
            _output(false, "Failed to fetch data");
        }

        $label = array(
                "LabelIC",
                "LabelIClama",
                "Labelnama",
                "LabelTlahir",
                "Labeljantina",
                "Labellokaliti",
                "Labeldm",
                "Labeldun",
                "Labelpar",
                "Labelnegeri",
                "LABELSTATUSDPI",
		"Labelpusatmengundi",
		"Labelsaluran",
		"Labelmasastart",
		"Labelmasaend",
		"Labelno"
        );

        $output = array();
        foreach($label as $key) {
            if ( preg_match("/{$key}\"\>(.*?)\<\/span\>/", $result, $mm) ) {
                $output[$key] = trim($mm[1]);
            }
        }

        if ( !_array($output) ) {
            _output(false, "Failed to get data");
        }

        $output['timestamp'] = time();
        $c = $output;
        $c['ct'] = 1;

        $this->cache_save($this->ic, $c);
        _output(true, "Fetch OK", $output);

    }
}

$spr = new spr();
$spr->output();


