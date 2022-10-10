<?php
namespace Wainwright\CasinoDog\Controllers\Game\Oaks;

use Illuminate\Support\Facades\Http;
use Wainwright\CasinoDog\Facades\ProxyHelperFacade;
use Wainwright\CasinoDog\Controllers\Game\GameKernelTrait;
use Illuminate\Support\Facades\Cache;

class OaksGame extends OaksMain
{
    use GameKernelTrait;


    public function bridged($request) {
        $internal_token = $request->internal_token;
        $select_session = $this->get_internal_session($internal_token)['data'];
        $url = $_SERVER['REQUEST_URI'];
        $exploded_url = explode(';jsession', $url);
        if(isset($exploded_url[1])) {
        $callback_url = 'https://netentff-game.casinomodule.com/servlet/CasinoGameServlet;jsession'.$exploded_url[1];
        $http = Http::get($callback_url);
            if($request->action === 'init') {
                $data_origin = $this->parse_query($http);
                $get_balance = $this->get_balance($internal_token);
                $credit_current = $this->in_between("\&credit=", "\&", $http);
                if($credit_current) {
                    $bridge_balance = (int) Cache::set($internal_token.':netentHiddenBalance',  (int) $data_origin['credit']);
                    $http = str_replace('credit='.$credit_current, 'credit='.$get_balance, $http);
                }
                $http = str_replace('playforfun=true', 'playforfun=false', $http);
                $http = str_replace('g4mode=false', 'g4mode=true', $http);
                return $http;
            }

            $data_origin = $this->parse_query($http);
            $data_origin['playforfun'] = false;
            $data_origin['g4mode'] = true;

            if(isset($data_origin['credit'])) {
                $bridge_balance = (int) Cache::get($internal_token.':netentHiddenBalance');
                if(!$bridge_balance) {
                    $bridge_balance = (int) Cache::set($internal_token.':netentHiddenBalance',  (int) $data_origin['credit']);
                }
                $current_balance = (int) $data_origin['credit'];
                if($bridge_balance !== $current_balance) {
                    if($bridge_balance > $current_balance) {
                        $winAmount = 0;
                        $betAmount = $bridge_balance - $current_balance;
                    } else {
                        $betAmount = 0;
                        $winAmount = $current_balance - $bridge_balance;
                    }
                Cache::set($internal_token.':netentHiddenBalance',  (int) $current_balance);
                $process_and_get_balance = $this->process_game($internal_token, ($betAmount ?? 0), ($winAmount ?? 0), $data_origin);
                $data_origin['credit'] = (int) $process_and_get_balance;
                } else {
                    Cache::set($internal_token.':netentHiddenBalance',  (int) $current_balance);
                    $get_balance = $this->get_balance($internal_token);
                    $data_origin['credit'] = (int) $get_balance;
                }
            }

            $build = $this->build_query($data_origin);
            $final = str_replace('_', '.', $build);
	    return $final;

        } else {
            $callback_url = 'https://netentff-game.casinomodule.com/mobile-game-launcher/version';
            $send_request = $this->curl_request($callback_url, $request);
            return $send_request;
        }
    }



    public function game_event($request)
    {
        $url = $_SERVER['REQUEST_URI'];
        $url = explode('origin_url=', $url);
        $replace_url = str_replace('/&gsc', '/?gsc', $url[1]);
        $final_url = $replace_url;


	    $data_origin = $this->curl_request($final_url, $request);
        $data_origin = json_decode($data_origin, true);
        $internal_token = $request->internal_token;
        $select_session = $this->get_internal_session($internal_token)['data'];


        if($request->gsc === 'sync') {
          $get_cached_balance = Cache::get('oaks:sync:balance-'.$data_origin['session_id']);
          if($get_cached_balance) {
            $data_origin['user']['balance'] = $get_cached_balance;
            $data_origin['user']['currency'] = $select_session['currency'];
          } else {
            $balance = (int) $this->get_balance($internal_token);
            Cache::put('oaks:sync:balance-'.$data_origin['session_id'], $balance, 60);
            $data_origin['user']['balance'] = $balance;
            $data_origin['user']['currency'] = $select_session['currency'];
          }
          return $data_origin;
        }

        $balance_call_needed = 1;

        if($request->gsc === 'play') {
            if(isset($data_origin['context'])) {
                if(isset($data_origin['context']['spins'])) {
                    $round_bet = 0;
                    $round_win = 0;
                    $process_game_needed = 0;
                    if(isset($data_origin['context']['spins']['round_bet'])) {
                        $round_bet = $data_origin['context']['spins']['round_bet'];
                        if($round_bet > 0) {
                            $process_game_needed = 1;
                        }
                    }

                    if(isset($data_origin['context']['spins']['round_win'])) {
                        $round_win = $data_origin['context']['spins']['round_win'];
                        if($round_win > 0) {
                            $process_game_needed = 1;
                        }
                    }

                    if($process_game_needed === 1) {
                        $balance_call_needed = 0;
                        $process_game = $this->process_game($internal_token, $round_bet, $round_win, $data_origin);
                        Cache::put('oaks:sync:balance-'.$data_origin['session_id'], $process_game, 60);
                    }

                }
            }
        }

        if(isset($data_origin['user'])) {
            if($balance_call_needed === 1) {
                $balance = (int) $this->get_balance($internal_token);
                Cache::put('oaks:sync:balance-'.$data_origin['session_id'], $balance, 60);
                $data_origin['user']['balance'] = $balance;
                $data_origin['user']['currency'] = $select_session['currency'];
            } else {
                $data_origin['user']['balance'] = $process_game;
                $data_origin['user']['currency'] = $select_session['currency'];
            }
        }


        return $data_origin;
    }



    public function curl_modified_request($url, $data, $request)
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $headers = array(
        "authority: betman-demo.head.3oaks.com",
        "accept: */*",
        "accept-language: en-ZA,en;q=0.9",
        "content-type: text/plain",
        "refferer: https://3oaks.com",
        "sec-ch-ua-mobile: ?0",
        "sec-fetch-dest: empty",
        "origin: https://3oaks.com",
        "sec-fetch-mode: cors",
        "sec-fetch-site: same-site",
        "user-agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/105.0.5195.127 Safari/537.36",
        );
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($curl, CURLOPT_TIMEOUT, 5);
        $resp = curl_exec($curl);
        curl_close($curl);

        return $resp;

        $resp = ProxyHelperFacade::CreateProxy($request)->toUrl($url);

        return $resp;
    }

    public function curl_request($url, $request)
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $headers = array(
        "authority: betman-demo.head.3oaks.com",
        "accept: */*",
        "accept-language: en-ZA,en;q=0.9",
        "content-type: text/plain",
        "refferer: https://3oaks.com",
        "sec-ch-ua-mobile: ?0",
        "sec-fetch-dest: empty",
        "origin: https://3oaks.com",
        "sec-fetch-mode: cors",
        "sec-fetch-site: same-site",
        "user-agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/105.0.5195.127 Safari/537.36",
        );
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $data = $request->getContent();
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($curl, CURLOPT_TIMEOUT, 5);
        $resp = curl_exec($curl);
        curl_close($curl);

        return $resp;

        $resp = ProxyHelperFacade::CreateProxy($request)->toUrl($url);

        return $resp;
    }
}
