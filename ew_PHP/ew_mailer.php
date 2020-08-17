<?php
/* Copyright 2017 - 2019 RAGE Software Inc. All Rights Reserved */
class EverWeb_Mail {
	public $server;
	public $port;
	public $username;
	public $password;
	public $secure;    
	public $charset="\"UTF-8\""; 
	public $contentType="multipart/mixed";  
	public $transferEncodeing="quoted-printable"; 
	public $altBody="";
	public $isLogin=false;
	public $recipients=array();
	public $cc=array();
	public $bcc=array();
	public $attachments=array();
	public $errorMessage="";
	public $hasError=false;
	private $smtpskt;
	private $newline="\r\n";
	private $localhost='localhost';
	private $timeout='30';
	private $debug=false;
	private $debugLog=array();
	public function __construct($server=null, $port=null, $username=null, $password=null, $secure=null) {
		$this->errorMessage="";
		$this->hasError=false;
		$this->isLogin=false;
		$this->server=$server;
		$this->port=$port;
		$this->username=$username;
		$this->password=$password;
		$this->secure=$secure;
	}
	public function printDebugLog() {
		foreach($this->debugLog as $key=> $value) {
    		print "[$key]=$value<br />";
 		}
 		print $this->errorMessage;
	}
	private function doConnect() {
		$this->debugLog['start']="Connecting to ". $this->server ." on port ". strval($this->port);
		if ($this->isModeSSL()) {
			$this->server='ssl://' . $this->server;
		}
		$this->smtpskt=@fsockopen($this->server, $this->port, $errno, $errstr, $this->timeout);
		if (empty($this->smtpskt)) {
			$this->debugLog['connection']="Can't connect to ($errstr) ". $this->server;
			$this->hasError=true;
			$this->errorMessage="Can't connect to ". $this->server . " error: " . ($errstr);
			return false;
		} else {
			$result=$this->getServerResponse();
			$this->debugLog['connection']=$result;
			if ($this->parseResultCode($result)==220) {
				return true;
			} else {
				$this->hasError=true;
				$this->errorMessage="Error connecting to SMTP server: ".  $this->parseResultCode($result);
				$this->debugLog['error']="Error connecting to SMTP server";
				return false;
			}
		}
	}
	function sendCommand($command) {
		fputs($this->smtpskt, $command . $this->newline);
		return $this->getServerResponse();
	}
	function parseResultCode($response) {
		$result=substr($response,0,3);
		return $result;
	}
	function isModeTLS() {
		if (strtolower(trim(substr($this->secure,0,3)))=='tls') {
			return true;
		} else {
			return false;
		}
	}
	function getTLSMode() {
		$version=strtolower(trim(substr($this->secure,3,1)));
		return $version;
	}
	function isModeSSL() {
		if (strtolower(trim($this->secure))=='ssl') {
			return true;
		} else {
			return false;
		}
	}
	
	function getDomainFromEmail($emailAddr) {
		return explode('@', $emailAddr, 2)[1];
		
	}
	
	private function sendAuth() {
		$result=$this->sendCommand('EHLO ' . $this->server );
		$this->debugLog['helo']=$result;
		if ($this->isModeTLS()) {
			$this->debugLog['tls']=$this->sendCommand("STARTTLS");
			if (!defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
				stream_socket_enable_crypto($this->smtpskt, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
			} else {
				$intTLSMode=$this->getTLSMode();
				switch ($intTLSMode) {
				case 1:
					stream_socket_enable_crypto($this->smtpskt, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
					break;
				case 11:
					stream_socket_enable_crypto($this->smtpskt, true, STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT);
					break;
				case 12:
					stream_socket_enable_crypto($this->smtpskt, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
					break;
				default:
					stream_socket_enable_crypto($this->smtpskt, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
				}
			}
			$result=$this->sendCommand('EHLO ' . $this->server );
			$this->debugLog['helo']=$result;
		}
		$this->debugLog['auth']=$this->sendCommand("AUTH LOGIN");
		if ($this->parseResultCode($this->debugLog['auth']) !=334) {
			$this->hasError=true;
			$this->errorMessage="Authentication command not accepted by the server. Confirm correct server address and port.";
			return false;
		}
		$this->debugLog['username']=$this->sendCommand(base64_encode($this->username));
		$this->debugLog['password']=$this->sendCommand(base64_encode($this->password));
		if ($this->parseResultCode($this->debugLog['password'])==535) {
			$this->debugLog['error']="Incorrect login details. Check your username and password";
			$this->hasError=true;
			$this->errorMessage="Incorrect login details. Check your username and password";
			return false;
		}
		return true;
	}
	public function sendMail($from, $to, $subject, $message, $headers=null) {
	 	//but if that is missing, we should just use PHP's mail function
	 	if (!empty($headers)) {
			foreach($headers as $key=> $value) {
				$headers[$key]=preg_replace('=((<CR>|<LF>|0x0A/%0A|0x0D/%0D|\\n|\\r)\S).*=i', null, $value);
			}
	    }
	    
	 	if ($this->server=='' || $this->username=='' || $this->password=='') {
	 		//send using PHP mail's function, less reliable
	 		$this->debugLog['mail']="Sending with PHP mail";
	 		if (!$headers =='') {
	 			//only add headers if there are any custom ones because a new line here will cause issues
	 			$emailHeader = $headers .  $this->newline;
	 		}
	 		$emailHeader .="Return-Path: " . $from .  $this->newline;
			$emailHeader .="From:" . $to .  $this->newline;
			$emailHeader .="X-Mailer: EverWeb with PHP " . phpversion() .  $this->newline;
			$emailHeader .="Reply-To: " . $from .  $this->newline;
			$emailHeader .="X-Priority: 3 (Normal)" . $this->newline;
			$emailHeader .="Mime-Version: 1.0" .  $this->newline;
			$emailHeader .="Content-type: text/html; charset=utf-8";
			
			
			if (mail($to, $subject, $message, $emailHeader)) {
				return true;
			} else {
				return false;
			}
	 	} else {
			if(!$this->doConnect())  {
				return false;
			} else {
				if (!$this->sendAuth()) {
					return false;
				}
				$this->isLogin=true;
			}
			$email="Date: " . date("D, j M Y G:i:s") . " -0500" . $this->newline;
			$email .="From: $to" . $this->newline;
			$email .="Reply-To: $from" . $this->newline;
			$email .=$this->setRecipients($to);
			if ($headers !=null) { 
				$email .=$headers . $this->newline; 
			}
			$email .="Subject: $subject" . $this->newline;
			$email .="MIME-Version: 1.0" . $this->newline;
			$email .="X-Mailer: EverWeb with PHP " . phpversion() .  $this->newline;
			$email .="Message-ID: <" . base64_encode(time()) . "." . base64_encode(mt_rand()) . "@" . $this->getDomainFromEmail($from) . ">" .  $this->newline;
			
			if($this->contentType=="multipart/mixed") {
			  $boundary=$this->generateBoundary();
			  $message=$this->multipartMessage($message,$boundary);
			  $email .="Content-Type: $this->contentType;" . $this->newline;
			  $email .="    boundary=\"$boundary\"";
			} else {
			  $email .="Content-Type: $this->contentType; charset=$this->charset";
			}
			$email .=$this->newline . $this->newline . $message . $this->newline;
			$email .="." . $this->newline;
			$this->debugLog['mail']=$this->sendCommand('MAIL FROM: <'. $this->getMailAddr($from) .'>');
			if ($this->parseResultCode($this->debugLog['mail']) !=250) {
				$this->hasError=true;
				$this->errorMessage="Unable to send MAIL FROM command";
				return false;
			}
			if(!$to=='') {
				$this->debugLog['rcpt']=$this->sendCommand('RCPT TO: <'. $this->getMailAddr($to) .'>');
			}
			$this->sendRecipients($this->recipients);
			$this->sendRecipients($this->cc);
			$this->sendRecipients($this->bcc);
			$this->debugLog['data']=$this->sendCommand('DATA');
			if ($this->parseResultCode($this->debugLog['data']) !=354) {
				$this->hasError=true;
				$this->errorMessage="Unable to send DATA command";
				return false;
			}
			$this->debugLog['email']=$this->sendCommand($email);
			if ($this->parseResultCode($this->debugLog['email'])==250) {
				return true;
			} else {
				$this->hasError=true;
				$this->errorMessage="Unable to send mail: " . $this->debugLog['email'];
				return false;
			}
		}
	}
	
	
	
	
	private function setRecipients($to) {
		$r='To: ';
		$to=$this->fixDomain($to);
		if(!($to=='')) { 
			$r .=$to . ','; 
		}
		if(count($this->recipients)>0) {
			for($i=0;$i<count($this->recipients);$i++) {
				$r .=$this->recipients[$i] . ',';
			}
		}
		$r=substr($r,0,-1) . $this->newline;  
		if(count($this->cc)>0) {
			$r .='CC: ';
			for($i=0;$i<count($this->cc);$i++) {
				$r .=$this->cc[$i] . ',';
			}
			$r=substr($r,0,-1) . $this->newline;   
		}
		return $r;
	}
	private function sendRecipients($r) {
	  if(empty($r)) { return; }
		for($i=0;$i<count($r);$i++) {
			$this->debugLog['rcpt-to']=$this->sendCommand('RCPT TO: <'. $this->getMailAddr($r[$i]) .'>');
		}
	}
	public function addRecipient($recipient) {
		$recipient=$this->fixDomain($recipient);
		$this->recipients[]=$recipient;
	}
	public function clearRecipients() {
		unset($this->recipients);
		$this->recipients=array();
	}
	public function addCC($c) {
		$c=$this->fixDomain($c);
		$this->cc[]=$c;
	}
	public function clearCC() {
		unset($this->cc);
		$this->cc=array();
	}
	public function addBCC($bc) {
		$bc=$this->fixDomain($bc);
		$this->bcc[]=$bc;
	}
	public function clearBCC() {
		unset($this->bcc);
		$this->bcc=array();
	}
	public function addAttachment($filePath) {
		$this->attachments[]=$filePath;
	}
	public function clearAttachments() {
		unset($this->attachments);
		$this->attachments=array();
	}
	function __destruct() {
		$this->errorMessage="";
		$this->hasError=false;
		if ($this->isLogin) {
			$this->debugLog['quit']=$this->sendCommand('QUIT');
			fclose($this->smtpskt);
		}
	}
	private function getServerResponse() {
		$data="";
		while( $str=fgets($this->smtpskt,4096) ) {
		  $data .=trim($str);
		  if(substr($str,3,1)==" ") { 
		  	break; 
		  }
		}
		if($this->debug) echo $data . "<br>";
		return $data;
	}
	private function getMailAddr($emailaddr) {
	   $addr=$emailaddr;
	   $strSpace=strrpos($emailaddr,' ');
	   if($strSpace > 0) {
	     $addr=substr($emailaddr,$strSpace+1);
	     $addr=str_replace("<","",$addr);
	     $addr=str_replace(">","",$addr);
	   }
	   return $addr;
	}
	private function randID($len) {
	  $index="abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ";
	  $out="";
	  for ($t=0; $t<$len;$t++) {
	    $r=rand(0,61);
	    $out=$out . substr($index,$r,1);
	  }
	  return $out;
	}
	function fixDomain($domain) {
		if(!function_exists('idn_to_ascii')) {
			return $domain;
		} else {
			return idn_to_ascii($domain);
		}
	}
	private function generateBoundary() {
		$boundary="--=_NextPart_000_";
		$boundary .=$this->randID(4) . "_";
		$boundary .=$this->randID(8) . ".";
		$boundary .=$this->randID(8);
		return $boundary;
	}
	private function multipartMessage($htmlpart,$boundary) {
		if ($this->altBody=="") { 
	  		$this->altBody=removeHTMLTags($htmlpart); 
	  	}
		$altBoundary=$this->generateBoundary();
		ob_start(); 
		$parts="This is a multi-part message in MIME format." . $this->newline . $this->newline;
		$parts .="--" . $boundary . $this->newline;
		$parts .="Content-Type: multipart/alternative;" . $this->newline;
		$parts .="    boundary=\"$altBoundary\"" . $this->newline . $this->newline;
		$parts .="--" . $altBoundary . $this->newline;
		$parts .="Content-Type: text/plain; charset=$this->charset" . $this->newline;
		$parts .="Content-Transfer-Encoding: $this->transferEncodeing" . $this->newline . $this->newline;
		$parts .=$this->altBody . $this->newline . $this->newline;
		$parts .="--" . $altBoundary . $this->newline;
		$parts .="Content-Type: text/html; charset=$this->charset" . $this->newline;
		$parts .="Content-Transfer-Encoding: $this->transferEncodeing" . $this->newline . $this->newline;
		$parts .=$htmlpart . $this->newline . $this->newline;
		$parts .="--" . $altBoundary . "--" . $this->newline . $this->newline;
		if(count($this->attachments) > 0) {
		  for($i=0;$i<count($this->attachments);$i++) {
				$attachment=chunk_split(base64_encode(file_get_contents($this->attachments[$i])));
				$filename=basename($this->attachments[$i]);
				$ext=pathinfo($filename, PATHINFO_EXTENSION);
				$parts .="--" . $boundary . $this->newline;
			    $parts .="Content-Type: application/$ext; name=\"$filename\"" . $this->newline;
				$parts .="Content-Transfer-Encoding: base64" . $this->newline;
				$parts .="Content-Disposition: attachment; filename=\"$filename\"" . $this->newline . $this->newline;
				$parts .=$attachment . $this->newline;
		  }
		}
		$parts .="--" . $boundary . "--";
		$message=ob_get_clean(); 
		return $parts;
	}
	private function removeHTMLTags( $text )
	{
		$text=preg_replace(
			array(
				'@<head[^>]*?>.*?</head>@siu',
				'@<style[^>]*?>.*?</style>@siu',
				'@<script[^>]*?.*?</script>@siu',
				'@<object[^>]*?.*?</object>@siu',
				'@<embed[^>]*?.*?</embed>@siu',
				'@<applet[^>]*?.*?</applet>@siu',
				'@<noframes[^>]*?.*?</noframes>@siu',
				'@<noscript[^>]*?.*?</noscript>@siu',
				'@<noembed[^>]*?.*?</noembed>@siu',
				'@<form[^>]*?.*?</form>@siu',
				'@<((br)|(hr))>@iu',
				'@</?((address)|(blockquote)|(center)|(del))@iu',
				'@</?((div)|(h[1-9])|(ins)|(isindex)|(p)|(pre))@iu',
				'@</?((dir)|(dl)|(dt)|(dd)|(li)|(menu)|(ol)|(ul))@iu',
				'@</?((table)|(th)|(td)|(caption))@iu',
				'@</?((form)|(button)|(fieldset)|(legend)|(input))@iu',
				'@</?((label)|(select)|(optgroup)|(option)|(textarea))@iu',
				'@</?((frameset)|(frame)|(iframe))@iu',
			),
			array(
				" ", " ", " ", " ", " ", " ", " ", " ", " ", " ", 
				" ", "\n\$0", "\n\$0", "\n\$0", "\n\$0", "\n\$0",
				"\n\$0", "\n\$0",
			),
			$text );
		$text=preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $text);
		$text=preg_replace("/\n( )*/", "\n", $text);
		return strip_tags( $text );
	}
}
?>