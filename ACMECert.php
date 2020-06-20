<?php

/*
  MIT License

  Copyright (c) 2018 Stefan Körfgen

  Permission is hereby granted, free of charge, to any person obtaining a copy
  of this software and associated documentation files (the "Software"), to deal
  in the Software without restriction, including without limitation the rights
  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
  copies of the Software, and to permit persons to whom the Software is
  furnished to do so, subject to the following conditions:

  The above copyright notice and this permission notice shall be included in all
  copies or substantial portions of the Software.

  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
  SOFTWARE.
*/

// https://github.com/skoerfgen/ACMECert

class ACMECert extends ACMEv2 { // ACMECert - PHP client library for Let's Encrypt (ACME v2)

	public function register($termsOfServiceAgreed=false,$contacts=array()){
		$this->log('Registering account');

		$ret=$this->request('newAccount',array(
			'termsOfServiceAgreed'=>(bool)$termsOfServiceAgreed,
			'contact'=>$this->make_contacts_array($contacts)
		));
		$this->log($ret['code']==201?'Account registered':'Account already registered');
		return $ret['body'];
	}

	public function update($contacts=array()){
		$this->log('Updating account');
		$ret=$this->request($this->getAccountID(),array(
			'contact'=>$this->make_contacts_array($contacts)
		));
		$this->log('Account updated');
		return $ret['body'];
	}

	public function getAccount(){
		$ret=parent::getAccount();
		return $ret['body'];
	}

	public function deactivateAccount(){
		$this->log('Deactivating account');
		$ret=$this->deactivate($this->getAccountID());
		$this->log('Account deactivated');
		return $ret;
	}

	public function deactivate($url){
		$this->log('Deactivating resource: '.$url);
		$ret=$this->request($url,array('status'=>'deactivated'));
		$this->log('Resource deactivated');
		return $ret['body'];
	}

	public function keyChange($new_account_key_pem){ // account key roll-over
		$ac2=new ACMEv2();
		$ac2->loadAccountKey($new_account_key_pem);
		$account=$this->getAccountID();
		$ac2->resources=$this->resources;

		$this->log('Account Key Roll-Over');

		$ret=$this->request('keyChange',
			$ac2->jws_encapsulate('keyChange',array(
				'account'=>$account,
				'oldKey'=>$this->jwk_header['jwk']
			),true)
		);
		$this->log('Account Key Roll-Over successful');

		$this->loadAccountKey($new_account_key_pem);
		return $ret['body'];
	}

	public function revoke($pem){
		if (false===($res=openssl_x509_read($pem))){
			throw new Exception('Could not load certificate: '.$pem.' ('.$this->get_openssl_error().')');
		}
		if (false===(openssl_x509_export($res,$certificate))){
			throw new Exception('Could not export certificate: '.$pem.' ('.$this->get_openssl_error().')');
		}

		$this->log('Revoking certificate');
		$this->request('revokeCert',array(
			'certificate'=>$this->base64url($this->pem2der($certificate))
		));
		$this->log('Certificate revoked');
	}

	public function getCertificateChain($pem,$domain_config,$callback){
		$domain_config=array_change_key_case($domain_config,CASE_LOWER);
		$domains=array_keys($domain_config);

		// autodetect if Private Key or CSR is used
		if ($key=openssl_pkey_get_private($pem)){ // Private Key detected
			openssl_free_key($key);
			$this->log('Generating CSR');
			$csr=$this->generateCSR($pem,$domains);
		}elseif(openssl_csr_get_subject($pem)){ // CSR detected
			$this->log('Using provided CSR');
			if (0===strpos($pem,'file://')) {
				$csr=file_get_contents(substr($pem,7));
				if (false===$csr) {
					throw new Exception('Failed to read CSR from '.$pem.' ('.$this->get_openssl_error().')');
				}
			}else{
				$csr=$pem;
			}
		}else{
			throw new Exception('Could not load Private Key or CSR ('.$this->get_openssl_error().'): '.$pem);
		}

		$this->getAccountID(); // get account info upfront to avoid mixed up logging order

		// === Order ===
		$this->log('Creating Order');
		$ret=$this->request('newOrder',array(
			'identifiers'=>array_map(
				function($domain){
					return array('type'=>'dns','value'=>$domain);
				},
				$domains
			)
		));
		$order=$ret['body'];
		$order_location=$ret['headers']['location'];
		$this->log('Order created: '.$order_location);

		// === Authorization ===
		if ($order['status']==='ready') {
			$this->log('All authorizations already valid, skipping validation altogether');
		}else{
			$groups=array();
			$auth_count=count($order['authorizations']);

			foreach($order['authorizations'] as $idx=>$auth_url){
				$this->log('Fetching authorization '.($idx+1).' of '.$auth_count);
				$ret=$this->request($auth_url,'');
				$authorization=$ret['body'];

				// wildcard authorization identifiers have no leading *.
				$domain=( // get domain and add leading *. if wildcard is used
					isset($authorization['wildcard']) &&
					$authorization['wildcard'] ?
					'*.':''
				).$authorization['identifier']['value'];

				if ($authorization['status']==='valid') {
					$this->log('Authorization of '.$domain.' already valid, skipping validation');
					continue;
				}

				// groups are used to be able to set more than one TXT Record for one subdomain
				// when using dns-01 before firing the validation to avoid DNS caching problem
				$groups[
					$domain_config[$domain]['challenge'].
					'|'.
					ltrim($domain,'*.')
				][$domain]=array($auth_url,$authorization);
			}

			// make sure dns-01 comes last to avoid DNS problems for other challenges
			krsort($groups);

			foreach($groups as $group){
				$pending_challenges=array();

				try { // make sure that pending challenges are cleaned up in case of failure
					foreach($group as $domain=>$arr){
						list($auth_url,$authorization)=$arr;

						$config=$domain_config[$domain];
						$type=$config['challenge'];

						$challenge=$this->parse_challenges($authorization,$type,$challenge_url);

						$opts=array(
							'domain'=>$domain,
							'config'=>$config
						);
						list($opts['key'],$opts['value'])=$challenge;

						$this->log('Triggering challenge callback for '.$domain.' using '.$type);
						$remove_cb=$callback($opts);

						$pending_challenges[]=array($remove_cb,$opts,$challenge_url,$auth_url);
					}

					foreach($pending_challenges as $arr){
						list($remove_cb,$opts,$challenge_url,$auth_url)=$arr;
						$this->log('Notifying server for validation of '.$opts['domain']);
						$this->request($challenge_url,new StdClass);

						$this->log('Waiting for server challenge validation');
						sleep(1);

						if (!$this->poll('pending',$auth_url,$body)) {
							$this->log('Validation failed: '.$opts['domain']);

							$ret=array_values(array_filter($body['challenges'],function($item){
								return isset($item['error']);
							}));

							$error=$ret[0]['error'];
							throw new ACME_Exception($error['type'],'Challenge validation failed: '.$error['detail']);
						}else{
							$this->log('Validation successful: '.$opts['domain']);
						}
					}

				}finally{ // cleanup pending challenges
					foreach($pending_challenges as $arr){
						list($remove_cb,$opts)=$arr;
						if ($remove_cb) {
							$this->log('Triggering remove callback for '.$opts['domain']);
							$remove_cb($opts);
						}
					}
				}
			}
		}

		$this->log('Finalizing Order');

		$ret=$this->request($order['finalize'],array(
			'csr'=>$this->base64url($this->pem2der($csr))
		));
		$ret=$ret['body'];

		if (isset($ret['certificate'])) {
			return $this->request_certificate($ret);
		}

		if ($this->poll('processing',$order_location,$ret)) {
			return $this->request_certificate($ret);
		}

		throw new Exception('Order failed');
	}

	public function generateCSR($domain_key_pem,$domains){
		if (false===($domain_key=openssl_pkey_get_private($domain_key_pem))){
			throw new Exception('Could not load domain key: '.$domain_key_pem.' ('.$this->get_openssl_error().')');
		}

		$fn=$this->tmp_ssl_cnf($domains);
		$dn=array('commonName'=>reset($domains));
		$csr=openssl_csr_new($dn,$domain_key,array(
			'config'=>$fn,
			'req_extensions'=>'SAN',
			'digest_alg'=>'sha512'
		));
		unlink($fn);
		openssl_free_key($domain_key);

		if (false===$csr) {
			throw new Exception('Could not generate CSR ! ('.$this->get_openssl_error().')');
		}
		if (false===openssl_csr_export($csr,$out)){
			throw new Exception('Could not export CSR ! ('.$this->get_openssl_error().')');
		}

		return $out;
	}

	private function generateKey($opts){
		$fn=$this->tmp_ssl_cnf();
		$config=array('config'=>$fn)+$opts;
		if (false===($key=openssl_pkey_new($config))){
			throw new Exception('Could not generate new private key ! ('.$this->get_openssl_error().')');
		}
		if (false===openssl_pkey_export($key,$pem,null,$config)){
			throw new Exception('Could not export private key ! ('.$this->get_openssl_error().')');
		}
		unlink($fn);
		openssl_free_key($key);
		return $pem;
	}
	
	public function generateRSAKey($bits=2048){
		return $this->generateKey(array(
			'private_key_bits'=>$bits,
			'private_key_type'=>OPENSSL_KEYTYPE_RSA
		));
	}
	
	public function generateECKey($curve_name='P-384'){
		if (version_compare(PHP_VERSION,'7.1.0')<0) throw new Exception('PHP >= 7.1.0 required for EC keys !');
		$map=array('P-256'=>'prime256v1','P-384'=>'secp384r1','P-521'=>'secp521r1');
		if (isset($map[$curve_name])) $curve_name=$map[$curve_name];
		return $this->generateKey(array(
			'curve_name'=>$curve_name,
			'private_key_type'=>OPENSSL_KEYTYPE_EC
		));
	}
	
	public function parseCertificate($cert_pem){
		if (false===($ret=openssl_x509_read($cert_pem))) {
			throw new Exception('Could not load certificate: '.$cert_pem.' ('.$this->get_openssl_error().')');
		}
		if (!is_array($ret=openssl_x509_parse($ret,true))) {
			throw new Exception('Could not parse certificate ('.$this->get_openssl_error().')');
		}
		return $ret;
	}

	public function getRemainingDays($cert_pem){
		$ret=$this->parseCertificate($cert_pem);
		return ($ret['validTo_time_t']-time())/86400;
	}

	public function generateALPNCertificate($domain_key_pem,$domain,$token){
		$domains=array($domain);
		$csr=$this->generateCSR($domain_key_pem,$domains);

		$fn=$this->tmp_ssl_cnf($domains,'1.3.6.1.5.5.7.1.31=critical,DER:0420'.$token."\n");
		$config=array(
			'config'=>$fn,
			'x509_extensions'=>'SAN',
			'digest_alg'=>'sha512'
		);
		$cert=openssl_csr_sign($csr,null,$domain_key_pem,1,$config);
		unlink($fn);
		if (false===$cert) {
			throw new Exception('Could not generate self signed certificate ! ('.$this->get_openssl_error().')');
		}
		if (false===openssl_x509_export($cert,$out)){
			throw new Exception('Could not export self signed certificate ! ('.$this->get_openssl_error().')');
		}
		return $out;
	}

	private function parse_challenges($authorization,$type,&$url){
		foreach($authorization['challenges'] as $challenge){
			if ($challenge['type']!=$type) continue;

			$url=$challenge['url'];

			switch($challenge['type']){
				case 'dns-01':
					return array(
						'_acme-challenge.'.$authorization['identifier']['value'],
						$this->base64url(hash('sha256',$this->keyAuthorization($challenge['token']),true))
					);
				break;
				case 'http-01':
					return array(
						'/.well-known/acme-challenge/'.$challenge['token'],
						$this->keyAuthorization($challenge['token'])
					);
				break;
				case 'tls-alpn-01':
					return array(null,hash('sha256',$this->keyAuthorization($challenge['token'])));
				break;
			}
		}
		throw new Exception('Challenge type: "'.$type.'" not available');
	}

	private function poll($initial,$type,&$ret){
		$max_tries=8;
		for($i=0;$i<$max_tries;$i++){
			$ret=$this->request($type);
			$ret=$ret['body'];
			if ($ret['status']!==$initial) return $ret['status']==='valid';
			$s=pow(2,min($i,6));
			if ($i!==$max_tries-1){
				$this->log('Retrying in '.($s).'s');
				sleep($s);
			}
		}
		throw new Exception('Aborted after '.$max_tries.' tries');
	}

	private function request_certificate($ret){
		$this->log('Requesting certificate-chain');
		$ret=$this->request($ret['certificate'],'');
		if ($ret['headers']['content-type']!=='application/pem-certificate-chain'){
			throw new Exception('Unexpected content-type: '.$ret['headers']['content-type']);
		}
		$this->log('Certificate-chain retrieved');
		return $ret['body'];
	}

	private function tmp_ssl_cnf($domains=null,$extension=''){
		if (false===($fn=tempnam(sys_get_temp_dir(), "CNF_"))){
			throw new Exception('Failed to create temp file !');
		}
		if (false===@file_put_contents($fn,
			'HOME = .'."\n".
			'RANDFILE=$ENV::HOME/.rnd'."\n".
			'[v3_ca]'."\n".
			'[req]'."\n".
			'default_bits=2048'."\n".
			($domains?
				'distinguished_name=req_distinguished_name'."\n".
				'[req_distinguished_name]'."\n".
				'[v3_req]'."\n".
				'[SAN]'."\n".
				'subjectAltName='.
				implode(',',array_map(function($domain){
					return 'DNS:'.$domain;
				},$domains))."\n"
			:
				''
			).$extension
		)){
			throw new Exception('Failed to write tmp file: '.$fn);
		}
		return $fn;
	}

	private function pem2der($pem) {
		return base64_decode(implode('',array_slice(
			array_map('trim',explode("\n",trim($pem))),1,-1
		)));
	}

	private function make_contacts_array($contacts){
		if (!is_array($contacts)) {
			$contacts=$contacts?array($contacts):array();
		}
		return array_map(function($contact){
			return 'mailto:'.$contact;
		},$contacts);
	}
}

class ACMEv2 { // Communication with Let's Encrypt via ACME v2 protocol

	protected
		$directories=array(
			'live'=>'https://acme-v02.api.letsencrypt.org/directory',
			'staging'=>'https://acme-staging-v02.api.letsencrypt.org/directory'
		),$ch=null,$bits,$sha_bits,$directory,$resources,$jwk_header,$kid_header,$account_key,$thumbprint,$nonce,$mode;

	public function __construct($live=true){
		$this->directory=$this->directories[$this->mode=($live?'live':'staging')];
	}

	public function __destruct(){
		if ($this->account_key) openssl_pkey_free($this->account_key);
		if ($this->ch) curl_close($this->ch);
	}

	public function loadAccountKey($account_key_pem){
		if ($this->account_key) openssl_pkey_free($this->account_key);
		if (false===($this->account_key=openssl_pkey_get_private($account_key_pem))){
			throw new Exception('Could not load account key: '.$account_key_pem.' ('.$this->get_openssl_error().')');
		}

		if (false===($details=openssl_pkey_get_details($this->account_key))){
			throw new Exception('Could not get account key details: '.$account_key_pem.' ('.$this->get_openssl_error().')');
		}

		$this->bits=$details['bits'];
		switch($details['type']){
			case OPENSSL_KEYTYPE_EC:
				if (version_compare(PHP_VERSION,'7.1.0')<0) throw new Exception('PHP >= 7.1.0 required for EC keys !');
				$this->sha_bits=($this->bits==521?512:$this->bits);
				$this->jwk_header=array( // JOSE Header - RFC7515
					'alg'=>'ES'.$this->sha_bits,
					'jwk'=>array( // JSON Web Key
						'crv'=>'P-'.$details['bits'],
						'kty'=>'EC',
						'x'=>$this->base64url(str_pad($details['ec']['x'],ceil($this->bits/8),"\x00",STR_PAD_LEFT)),
						'y'=>$this->base64url(str_pad($details['ec']['y'],ceil($this->bits/8),"\x00",STR_PAD_LEFT))
					)
				);
			break;
			case OPENSSL_KEYTYPE_RSA:
				$this->sha_bits=256;
				$this->jwk_header=array( // JOSE Header - RFC7515
					'alg'=>'RS256',
					'jwk'=>array( // JSON Web Key
						'e'=>$this->base64url($details['rsa']['e']), // public exponent
						'kty'=>'RSA',
						'n'=>$this->base64url($details['rsa']['n']) // public modulus
					)
				);
			break;
			default:
				throw new Exception('Unsupported key type! Must be RSA or EC key.');
			break;
		}

		$this->kid_header=array(
			'alg'=>$this->jwk_header['alg'],
			'kid'=>null
		);

		$this->thumbprint=$this->base64url( // JSON Web Key (JWK) Thumbprint - RFC7638
			hash(
				'sha256',
				json_encode($this->jwk_header['jwk']),
				true
			)
		);
	}

	public function getAccountID(){
		if (!$this->kid_header['kid']) self::getAccount();
		return $this->kid_header['kid'];
	}

	public function log($txt){
		error_log($txt);
	}

	protected function get_openssl_error(){
		$out=array();
		$arr=error_get_last();
		if (is_array($arr)){
			$out[]=$arr['message'];
		}
		$out[]=openssl_error_string();
		return implode(' | ',$out);
	}
	
	protected function getAccount(){
		$this->log('Getting account info');
		$ret=$this->request('newAccount',array('onlyReturnExisting'=>true));
		$this->log('Account info retrieved');
		return $ret;
	}

	protected function keyAuthorization($token){
		return $token.'.'.$this->thumbprint;
	}

	protected function request($type,$payload='',$retry=false){
		if (!$this->jwk_header) {
			throw new Exception('use loadAccountKey to load an account key');
		}

		if (!$this->resources){
			$this->log('Initializing ACME v2 '.$this->mode.' environment');
			$ret=$this->http_request($this->directory); // Read ACME Directory
			if (!is_array($ret['body'])) {
				throw new Exception('Failed to read directory: '.$this->directory);
			}
			$this->resources=$ret['body']; // store resources for later use
			$this->log('Initialized');
		}

		if (0===stripos($type,'http')) {
			$this->resources['_tmp']=$type;
			$type='_tmp';
		}

		try {
			$ret=$this->http_request($this->resources[$type],json_encode(
				$this->jws_encapsulate($type,$payload)
			));
		}catch(ACME_Exception $e){ // retry previous request once, if replay-nonce expired/failed
			if (!$retry && $e->getType()==='urn:ietf:params:acme:error:badNonce') {
				$this->log('Replay-Nonce expired, retrying previous request');
				return $this->request($type,$payload,true);
			}
			throw $e; // rethrow all other exceptions
		}

		if (!$this->kid_header['kid'] && $type==='newAccount'){
			$this->kid_header['kid']=$ret['headers']['location'];
			$this->log('AccountID: '.$this->kid_header['kid']);
		}

		return $ret;
	}
	
	protected function jws_encapsulate($type,$payload,$is_inner_jws=false){ // RFC7515
		if ($type==='newAccount' || $is_inner_jws) {
			$protected=$this->jwk_header;
		}else{
			$this->getAccountID();
			$protected=$this->kid_header;
		}

		if (!$is_inner_jws) {
			if (!$this->nonce) {
				$ret=$this->http_request($this->resources['newNonce'],false);
			}
			$protected['nonce']=$this->nonce;
		}

		$protected['url']=$this->resources[$type];

		$protected64=$this->base64url(json_encode($protected));
		$payload64=$this->base64url(is_string($payload)?$payload:json_encode($payload));

		if (false===openssl_sign(
			$protected64.'.'.$payload64,
			$signature,
			$this->account_key,
			'SHA'.$this->sha_bits
		)){
			throw new Exception('Failed to sign payload !'.' ('.$this->get_openssl_error().')');
		}

		return array(
			'protected'=>$protected64,
			'payload'=>$payload64,
			'signature'=>$this->base64url($this->jwk_header['alg'][0]=='R'?$signature:$this->asn2signature($signature,ceil($this->bits/8)))
		);
	}
	
	private function asn2signature($asn,$pad_len){
		if ($asn[0]!=="\x30") throw new Exception('ASN.1 SEQUENCE not found !');
		$asn=substr($asn,$asn[1]==="\x81"?3:2);
		if ($asn[0]!=="\x02") throw new Exception('ASN.1 INTEGER 1 not found !');
		$R=ltrim(substr($asn,2,ord($asn[1])),"\x00");
		$asn=substr($asn,ord($asn[1])+2);
		if ($asn[0]!=="\x02") throw new Exception('ASN.1 INTEGER 2 not found !');
		$S=ltrim(substr($asn,2,ord($asn[1])),"\x00");
		return str_pad($R,$pad_len,"\x00",STR_PAD_LEFT).str_pad($S,$pad_len,"\x00",STR_PAD_LEFT);
	}
	
	protected function base64url($data){ // RFC7515 - Appendix C
		return rtrim(strtr(base64_encode($data),'+/','-_'),'=');
	}
	
	private function json_decode($str){
		$ret=json_decode($str,true);
		if ($ret===null) {
			throw new Exception('Could not parse JSON: '.$str);
		}
		return $ret;
	}

	private function http_request($url,$data=null){
		if ($this->ch===null) {
			if (extension_loaded('curl') && $this->ch=curl_init()) {
				$this->log('Using cURL');
			}elseif(ini_get('allow_url_fopen')){
				$this->ch=false;
				$this->log('Using fopen wrappers');
			}else{
				throw new Exception('Can not connect, no cURL or fopen wrappers enabled !');
			}
		}
		$method=$data===false?'HEAD':($data===null?'GET':'POST');
		$user_agent='ACMECert v2.7 (+https://github.com/skoerfgen/ACMECert)';
		$header=($data===null||$data===false)?array():array('Content-Type: application/jose+json');
		if ($this->ch) {
			$headers=array();
			curl_setopt_array($this->ch,array(
				CURLOPT_URL=>$url,
				CURLOPT_FOLLOWLOCATION=>true,
				CURLOPT_RETURNTRANSFER=>true,
				CURLOPT_TCP_NODELAY=>true,
				CURLOPT_NOBODY=>$data===false,
				CURLOPT_USERAGENT=>$user_agent,
				CURLOPT_CUSTOMREQUEST=>$method,
				CURLOPT_HTTPHEADER=>$header,
				CURLOPT_POSTFIELDS=>$data,
				CURLOPT_HEADERFUNCTION=>function($ch,$header)use(&$headers){
					$headers[]=$header;
					return strlen($header);
				}
			));
			$took=microtime(true);
			$body=curl_exec($this->ch);
			$took=round(microtime(true)-$took,2).'s';
			if ($body===false) throw new Exception('HTTP Request Error: '.curl_error($this->ch));
		}else{
			$opts=array(
				'http'=>array(
					'header'=>$header,
					'method'=>$method,
					'user_agent'=>$user_agent,
					'ignore_errors'=>true,
					'timeout'=>60,
					'content'=>$data
				)
			);
			$took=microtime(true);
			$body=file_get_contents($url,false,stream_context_create($opts));
			$took=round(microtime(true)-$took,2).'s';
			if ($body===false) throw new Exception('HTTP Request Error: '.$this->get_openssl_error());
			$headers=$http_response_header;
		}
		
		$headers=array_reduce( // parse http response headers into array
			array_filter($headers,function($item){ return trim($item)!=''; }),
			function($carry,$item)use(&$code){
				$parts=explode(':',$item,2);
				if (count($parts)===1){
					list(,$code)=explode(' ',trim($item),3);
					$carry=array();
				}else{
					list($k,$v)=$parts;
					$carry[strtolower(trim($k))]=trim($v);
				}
				return $carry;
			},
			array()
		);
		$this->log('  '.$url.' ['.$code.'] ('.$took.')');

		if (!empty($headers['replay-nonce'])) $this->nonce=$headers['replay-nonce'];

		if (!empty($headers['content-type'])){
			switch($headers['content-type']){
				case 'application/json':
					$body=$this->json_decode($body);
				break;
				case 'application/problem+json':
					$body=$this->json_decode($body);
					throw new ACME_Exception($body['type'],$body['detail'],
						array_map(function($subproblem){
							return new ACME_Exception(
								$subproblem['type'],
								'"'.$subproblem['identifier']['value'].'": '.$subproblem['detail']
							);
						},isset($body['subproblems'])?$body['subproblems']:array())
					);
				break;
			}
		}

		if ($code[0]!='2') {
			throw new Exception('Invalid HTTP-Status-Code received: '.$code.': '.$url);
		}

		$ret=array(
			'code'=>$code,
			'headers'=>$headers,
			'body'=>$body
		);

		return $ret;
	}
}

class ACME_Exception extends Exception {
	private $type,$subproblems;
	function __construct($type,$detail,$subproblems=array()){
		$this->type=$type;
		$this->subproblems=$subproblems;
		parent::__construct($detail.' ('.$type.')');
	}
	function getType(){
		return $this->type;
	}
	function getSubproblems(){
		return $this->subproblems;
	}
}
