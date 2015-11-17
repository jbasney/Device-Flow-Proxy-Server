<?php
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use \Firebase\JWT\JWT;

class Controller {

  private function error(Response $response, $error, $error_description=false) {
    $data = [
      'error' => $error
    ];
    if($error_description) {
      $data['error_description'] = $error_description;
    }

    $response->setStatusCode(400);
    $response->setContent(json_encode($data));
    $response->headers->set('Content-Type', 'application/json');
    return $response;
  }

  private function success(Response $response, $data) {
    $response->setContent(json_encode($data));
    $response->headers->set('Content-Type', 'application/json');
    return $response;
  }

  # Home Page
  public function index(Request $request, Response $response) {
    $response->setContent(view('index', [
      'title' => 'TV Auth'
    ]));
    return $response;
  }

  # A device submits a request here to generate a new device and user code
  public function generate_code(Request $request, Response $response) {
    # Params:
    # client_id
    # scope
    # response_type=device_code

    # This server only supports the device_code response type
    if($request->get('response_type') != 'device_code') {
      return $this->error($response, 'unsupported_response_type', 'Only \'device_code\' is supported.');
    }

    # client_id is required
    if($request->get('client_id') == null) {
      return $this->error($response, 'invalid_request');
    }

    # We've validated everything we can at this stage.
    # Generate a verification code and cache it along with the other values in the request.
    $device_code = hash('sha256', time().rand().$request->get('client_id'));
    $cache = [
      'client_id' => $request->get('client_id'),
      // TODO: might need to also store client_secret here so that we can use it later
      'scope' => $request->get('scope'),
      'device_code' => $device_code
    ];
    $user_code = rand(100000,999999);
    Cache::set($user_code, $cache);

    # Add a placeholder entry with the device code so that the token route knows the request is pending
    Cache::set($device_code, [
      'timestamp' => time(),
      'status' => 'pending'
    ], 120);

    $data = [
      'device_code' => $device_code,
      'user_code' => $user_code,
      'verification_uri' => Config::$baseURL . '/device',
      'interval' => 5
    ];

    $response->setContent(json_encode($data));
    $response->headers->set('Content-Type', 'application/json');

    return $response;
  }

  # The user visits this page in a web browser
  # This interface provides a prompt to enter a device code, which then begins the actual OAuth flow
  public function device(Request $request, Response $response) {

    return $response;
  }

  # The browser submits a form that is a GET request to this route, which verifies
  # and looks up the user code, and then redirects to the real authorization server
  public function verify_code(Request $request, Response $response) {
    if($request->get('code') == null) {
      // TODO: return HTML error here
      return $this->error($response, 'invalid_request');
    }

    $cache = Cache::get($request->get('code'));
    if(!$cache) {
      // TODO: return HTML error here
      return $this->error($response, 'invalid_request', 'Code not found');
    }

    // TODO: might need to make this configurable to support OAuth 2 servers that have
    // custom parameters for the auth endpoint
    $state = JWT::encode([
      'user_code' => $request->get('code'),
      'time' => time()
    ], Config::$secretKey);
    $query = [
      'response_type' => 'code',
      'client_id' => $cache->client_id,
      'redirect_uri' => Config::$baseURL . '/auth/redirect',
      'state' => $state
    ];
    if($cache->scope)
      $query['scope'] = $cache->scope;

    $authURL = Config::$authServerURL . '?' . http_build_query($query);

    $response->headers->set('Location', $authURL);
    return $response;
  }

  # After the user logs in and authorizes the app on the real auth server, they will
  # be redirected back to here. We'll need to exchange the auth code for an access token,
  # and then show a message that instructs the user to go back to their TV and wait.
  public function redirect(Request $request, Response $response) {
    # Verify input params


    # Decode and verify the state parameter


    # Look up the info from the user code provided in the state parameter
    $cache = Cache::get($user_code);
    $device_code = $cache->device_code;

    # Exchange the authorization code for an access token


    # If there are any problems getting an access token, kill the request and display an error
    if($error) {
      Cache::delete($user_code);
      Cache::delete($device_code);
    }

    # Stash the access token in the cache and display a success message
    Cache::set($device_code, [
      'status' => 'complete',
      'token_response' => $access_token
    ], 120);
    Cache::delete($user_code);

    return $response;
  }

  # Meanwhile, the device is continually posting to this route waiting for the user to
  # approve the request. Once the user approves the request, this route returns the access token.
  # In addition to the standard OAuth error responses defined in https://tools.ietf.org/html/rfc6749#section-4.2.2.1
  # the server should return: authorization_pending and slow_down
  # TODO: presumably the device uses its device code as the authorization code here?
  public function access_token(Request $request, Response $response) {

    # Verify input params
    if($request->get('grant_type') != 'authorization_code') {
      return $this->error($response, 'invalid_request');
    }

    if($request->get('code') == null || $request->get('client_id') == null) {
      return $this->error($response, 'invalid_request');
    }

    $device_code = $request->get('code');

    #####################
    ## RATE LIMITING

    # Allow one request every 10 seconds, so divide the unix timestamp by 6 to get the rate limiting buckets
    $bucket = 'ratelimit-'.floor(time()/6).'-'.$device_code;

    if(Cache::get($bucket) >= 1) {
      return $this->error($response, 'slow_down');
    }

    # Mark for rate limiting
    Cache::add($bucket, 0, 60);
    Cache::incr($bucket);

    #####################

    # Check if the device code is in the cache
    $data = Cache::get($device_code);

    if(!$data) {
      return $this->error($response, 'invalid_grant');
    }

    if($data && $data->status == 'pending') {
      return $this->error($response, 'authorization_pending');
    } else if($data && $data->status == 'complete') {
      // return the raw access token response from the real authorization server
      return $this->sucecss($response, $data->token_response);
    } else {
      return $this->error($response, 'invalid_grant');
    }
  }

}
