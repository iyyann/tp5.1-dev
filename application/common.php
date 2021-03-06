<?php
/**
 * Created by PhpStorm.
 * User: lee <email:guoxinlee129@gmail.com>
 * Date: 2019/1/9
 * Time: 14:10
 */

//异步cmd，不等待
function execInBackground($cmd)
{
	if (substr(php_uname(), 0, 7) == "Windows") {
		pclose(popen("start /B " . $cmd, "r"));
	} else {
		exec($cmd . " > /dev/null &");
	}
}


/**
 * 加密解密
 * @param $string
 * @param $operation
 * @return bool|mixed|string
 */
function authCrypt($string, $operation = "E")
{
	$key = md5('oiuhf+u8985/*123-czJIOJ8012');
	$key_length = strlen($key);
	$string = $operation == 'D' ? base64_decode($string) : substr(md5($string . $key), 0, 8) . $string;
	$string_length = strlen($string);
	$rndkey = $box = array();
	$result = '';
	for ($i = 0; $i <= 255; $i++) {
		$rndkey[$i] = ord($key[$i % $key_length]);
		$box[$i] = $i;
	}
	for ($j = $i = 0; $i < 256; $i++) {
		$j = ($j + $box[$i] + $rndkey[$i]) % 256;
		$tmp = $box[$i];
		$box[$i] = $box[$j];
		$box[$j] = $tmp;
	}
	for ($a = $j = $i = 0; $i < $string_length; $i++) {
		$a = ($a + 1) % 256;
		$j = ($j + $box[$a]) % 256;
		$tmp = $box[$a];
		$box[$a] = $box[$j];
		$box[$j] = $tmp;
		$result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
	}
	if ($operation == 'D') {
		if (substr($result, 0, 8) == substr(md5(substr($result, 8) . $key), 0, 8)) {
			return substr($result, 8);
		} else {
			return '';
		}
	} else {
		return str_replace('=', '', base64_encode($result));
	}
}


/**
 * @param string $string 原文或者密文
 * @param string $operation 操作(ENCODE | DECODE), 默认为 DECODE
 * @param string $key 密钥
 * @param int $expiry 密文有效期, 加密时候有效， 单位 秒，0 为永久有效
 * @return string 处理后的 原文或者 经过 base64_encode 处理后的密文
 *************************
 * @example
 *
 *  $a = authcode('abc', 'ENCODE', 'key');
 *  $b = authcode($a, 'DECODE', 'key');  // $b(abc)
 *
 *  $a = authcode('abc', 'ENCODE', 'key', 3600);
 *  $b = authcode('abc', 'DECODE', 'key'); // 在一个小时内，$b(abc)，否则 $b 为空
 */
function authcodeExpiry($string, $operation = 'DECODE', $key = '', $expiry = 3600)
{

	// 随机密钥长度 取值 0-32;
	// 加入随机密钥，可以令密文无任何规律，即便是原文和密钥完全相同，加密结果也会每次不同，增大破解难度。
	// 取值越大，密文变动规律越大，密文变化 = 16 的 $ckey_length 次方
	// 当此值为 0 时，则不产生随机密钥
	$ckey_length = 4;

	$key = md5($key ? $key : 'default_key'); // 这里可以填写默认key值
	$keya = md5(substr($key, 0, 16));       // 密匙a会参与加解密   [keya = md5 新key前16位]
	$keyb = md5(substr($key, 16, 16));      // 密匙b会用来做数据完整性验证    [keyb = md5 新key后16位]
	// 密匙c用于变化生成的密文
	// 加密：keyc = 当前时间毫秒做md5加密，截取末尾随机秘钥长度字符
	// 解密：keyc = 截取传入的字符串string末尾随机秘钥长度字符
	$keyc = $ckey_length ? ($operation == 'DECODE' ? substr($string, 0, $ckey_length) : substr(md5(microtime()), -$ckey_length)) : '';
	// 参与运算的密匙
	$cryptkey = $keya . md5($keya . $keyc);
	$key_length = strlen($cryptkey);

	// 明文，前10位用来保存时间戳，解密时验证数据有效性，10到26位用来保存$keyb(密匙b)，解密时会通过这个密匙验证数据完整性
	// 如果是解码的话，会从第$ckey_length位开始，因为密文前$ckey_length位保存 动态密匙，以保证解密正确
	$string = $operation == 'DECODE' ? base64_decode(substr($string, $ckey_length)) : sprintf('%010d', $expiry ? $expiry + time() : 0) . substr(md5($string . $keyb), 0, 16) . $string;
	$string_length = strlen($string);
	$result = '';
	$box = range(0, 255);

	$rndkey = array();
	// 产生密匙簿
	for ($i = 0; $i <= 255; $i++) {
		$rndkey[$i] = ord($cryptkey[$i % $key_length]);
	}

	// 用固定的算法，打乱密匙簿，增加随机性，好像很复杂，实际上对并不会增加密文的强度
	for ($j = $i = 0; $i < 256; $i++) {
		$j = ($j + $box[$i] + $rndkey[$i]) % 256;
		$tmp = $box[$i];
		$box[$i] = $box[$j];
		$box[$j] = $tmp;
	}

	// 核心加解密部分
	for ($a = $j = $i = 0; $i < $string_length; $i++) {
		$a = ($a + 1) % 256;
		$j = ($j + $box[$a]) % 256;
		$tmp = $box[$a];
		$box[$a] = $box[$j];
		$box[$j] = $tmp;
		// 从密匙簿得出密匙进行异或，再转成字符
		$result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
	}

	if ($operation == 'DECODE') {
		// substr($result, 0, 10) == 0 验证数据有效性
		// substr($result, 0, 10) - time() > 0 验证数据有效性
		// substr($result, 10, 16) == substr(md5(substr($result, 26).$keyb), 0, 16) 验证数据完整性
		// 验证数据有效性，请看未加密明文的格式
		if ((substr($result, 0, 10) == 0 || substr($result, 0, 10) - time() > 0) && substr($result, 10, 16) == substr(md5(substr($result, 26) . $keyb), 0, 16)) {
			return substr($result, 26);
		} else {
			return '';
		}
	} else {
		// 把动态密匙保存在密文里，这也是为什么同样的明文，生产不同密文后能解密的原因
		// 因为加密后的密文可能是一些特殊字符，复制过程可能会丢失，所以用base64编码
		return $keyc . str_replace('=', '', base64_encode($result));
	}

}

/**
 * 获取唯一的id，中间是有字符的
 * @return string
 */
function getUniqueStr()
{
	return md5(uniqid(md5(microtime(true)), true));
}

/**
 * 获取唯一的id，中间是有字符的
 * @return mixed
 */
function getUniqueId()
{
	return uniqid(md5(microtime(true)), true);
}

/**
 * 加密密码 $salt
 * @param $str
 * @return bool|string
 * @throws Exception
 */
function cryptPassword($str)
{
	$result['salt'] = getUniqueStr();
	$result['password'] = crypt($str, $result['salt']);
	return $result;
}

//比对密码
function decryptPassword($newStr, $oldStr, $salt)
{
	return crypt($newStr, $salt) == $oldStr ? true : false;
}


/**
 * 使用curl_get方法获取相关数据
 * @param $url
 * @param int $httpCode
 * @return mixed
 */
function curl_get($url, &$httpCode = 0)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

	//--禁止SSL校验，linux环境下设置为true
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);// 不 检测服务器的域名与证书上的是否一致
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);   // 不 检测服务器的证书是否由正规浏览器认证过的授权CA颁发的

	$file_content = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return $file_content;
}

function curl_post_raw($content, $url)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	//    curl_setopt($ch,CURLOPT_HEADER,0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
	//    curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: text']);
	$data = curl_exec($ch);
	curl_close($ch);
	return $data;
}

/**
 * @param $url
 * @param string $save_dir
 * @param int $type
 * @return array|bool
 */
function getFile($url, $save_dir = DOWNLOAD_PATH, $cookies = null)//$save_dir=down/   $filename = "test.gif";
{
	ini_set("memory_limit", "1024M");
	try {
		$ch = curl_init();
		//        file_put_contents('neicun.txt', memory_get_usage());
		//        file_put_contents('neicun_fengzhi.txt', memory_get_peak_usage());
		if (!is_null($cookies)) {
			//            file_put_contents("cookie",$cookies);
			$curl_header[] = "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9";
			$curl_header[] = "Accept-Encoding: gzip, deflate, br";
			$curl_header[] = "Accept-Language: zh-CN,zh;q=0.9";
			$curl_header[] = "Connection: keep-alive";
			//            $curl_header[] = "Host: www.51miz.com";
			$curl_header[] = "Sec-Fetch-Mode: navigate";
			$curl_header[] = "Sec-Fetch-Site: none";
			$curl_header[] = "Sec-Fetch-User: ?1";
			$curl_header[] = "Upgrade-Insecure-Requests: 1";
			$curl_header[] = "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.117 Safari/537.36";
			$curl_header[] = "Cookie: {$cookies}";
			curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
		}
		curl_setopt($ch, CURLOPT_POST, 0);
		curl_setopt($ch, CURLOPT_HEADER, 1);                   //这是重点
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_ENCODING, "");
		curl_setopt($ch, CURLOPT_TIMEOUT, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		$response = curl_exec($ch);
		//    $error = curl_error($ch);

		// 根据头大小去获取头信息内容
		//        mb_convert_encoding($file_content,'UTF-8', 'UTF-8,GBK,GB2312,BIG5');

		//分离header与body
		$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE); //头信息size
		$header = substr($response, 0, $headerSize);
		$body = substr($response, $headerSize);

		curl_close($ch);
		//        $downloaded_file = fopen($save_dir.$filename.'.rar', 'w');
		//        fwrite($downloaded_file, $response);
		//        fclose($downloaded_file);

		$arr = array();
		//        file_put_contents("preg_result.txt",preg_match('/filename="(.*?)"/', $header, $arr));
		if (preg_match('/filename="(.*?)"/', $header, $arr)) {
			$file = urldecode($arr[1]);
		} else {
			//            file_put_contents('header.txt', $header);
			$header_arr = explode("\r\n", $header);
			$ext = "";
			foreach ($header_arr as $loop) {
				if (strpos($loop, "Content-Type") !== false) {
					$loop = str_replace(array("\r\n", "\r", "\n", "\t", " "), "", $loop);
					$loop_arr = explode(":", $loop);
					$ext = $loop_arr[1];
					//                    file_put_contents('$ext.txt', $ext);
					break;
				}
			}

			$file_ext = "";
			switch ($ext) {
				case "video/x-ms-wmv":
					$file_ext = '.wmv';
					break;
				case "application/x-ppt":
				case "application/vnd.ms-powerpoint":
					$file_ext = '.ppt';
					break;
				case "application/msword":
					$file_ext = '.doc';
					break;
				case "application/vnd.ms-excel":
					$file_ext = '.xls';
					break;
				case "video/mpeg4":
					$file_ext = '.mp4';
					break;
				case "video/quicktime":
					$file_ext = '.mov';
					break;
				case "audio/mp3":
					$file_ext = '.mp3';
					break;
				case "image/png":
					$file_ext = '.png';
					break;
				case "image/jpeg":
					$file_ext = '.jpeg';
					break;
				case "image/jpg":
					$file_ext = '.jpg';
					break;
				case "image/gif":
					$file_ext = '.gif';
					break;
				case "video/mpeg":
					$file_ext = '.mpeg';
					break;
				case "application/octet-stream":
					//二进制
					break;
				case "application/pdf":
					$file_ext = '.pdf';
					break;
				case "application/postscript":
					$file_ext = '.ai';
					break;
				case"application/atom+xml":
					$file_ext = '.xml';
					break;
				case "application/ecmascript":
					$file_ext = '.js';
					break;
				case "application/ogg":
					$file_ext = '.ogg';
					break;
				case "application/rdf+xml":
					$file_ext = '.rdf';
					break;
				case "application/rss+xml":
				case "application/soap+xml":
				case "application/xml":
				case "application/xop+xml":
					$file_ext = '.xml';
					break;
				case "application/font-woff":
					$file_ext = '.woff';
					break;
				case "application/xhtml+xml":
					$file_ext = '.xhtml';
					break;
				case"application/zip":
					$file_ext = '.zip';
					break;
				case "application/gzip":
					$file_ext = '.gzip';
					break;
				case "application/x-xls":
					$file_ext = '.xls';
					break;
			}
			if ($file_ext == null) {
				foreach ($header_arr as $loop) {
					if (strpos($loop, "Location") !== false) {
						//                        file_put_contents('Location.txt', $loop);
						$loop = str_replace(array("\r\n", "\r", "\n", "\t", " "), "", $loop);
						$zip = strpos($loop, ".zip");
						if ($zip !== false) {
							$ext = ".zip";
							unset($zip);
							break;
						}
						$rar = strpos($loop, ".rar");
						if ($rar !== false) {
							$ext = ".rar";
							unset($rar);
							break;
						}
						$mp4 = strpos($loop, ".mp4");
						if ($mp4 !== false) {
							$ext = ".rar";
							unset($mp4);
							break;
						}
						break;
					}
				}
			}
			if ($file_ext == null) {
				$file_ext = '.mp4';
			}

			//            file_put_contents("ext.txt", $file_ext);
			$file = getUniqueStr() . $file_ext;
		}
		$fullName = rtrim($save_dir, '/') . '/' . $file;//完整的路径+文件名
		//创建目录并设置权限
		$basePath = dirname($fullName);
		if (!file_exists($basePath)) {
			@mkdir($basePath, 0777, true);
			@chmod($basePath, 0777);
		}

		if (file_put_contents($fullName, $body)) {
			$header = null;
			$body = null;
			return array(
				'file_name' => $file,
				'save_path' => $fullName
			);
		}
	} catch (Exception $e) {
		$header = null;
		$file = null;
		$body = null;
		$fullName = null;
		return false;
	}
}