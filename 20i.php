<?php
/**
 * Roundcube Password Driver for 20i resellers.
 *
 * This driver changes a E-Mail-Password via 20i REST API
 * Deps: PHP-Curl
 *
 * @author     Harry Youd <harry@harryyoud.co.uk>
 * @copyright  Harry Youd, 2018
 *
 * Config needed:
 * $config['password_20i_token']     = 'FILL THIS';
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see http://www.gnu.org/licenses/.
 */



class rcube_20i_password {
	function save($currpass, $newpass) {
		$rcmail = rcmail::get_instance();
		$token  = $rcmail->config->get('password_20i_token');
		$rest = new rcube_20i_restapi($token);

		$email  = explode('@', $_SESSION['username']);
		$local  = $email[0];
		$domain = $email[1];

		$domains = $rest->getWithFields("https://api.20i.com/package/" . $domain . "/email/" . $domain);
		foreach ($domains as $mailboxes) {
			foreach ($mailboxes as $mailbox) {
				if ($mailbox->local == $local && (substr($mailbox->id, 0, 1) === 'm')) {
					rcube::console("Found ". $local ." as id=". $mailbox->id);
					$id = $mailbox->id;
					break;
				}
				continue;
			}
		}
		if (!$id) {
			return PASSWORD_ERROR;
		}
		$data = [
					"update" => [
									$id => 	[
												"password" => $newpass
											]
								]
				];
		rcube::console($rest->postWithFields("https://api.20i.com/package/" . $domain . "/email/" . $domain, $data));
		return PASSWORD_SUCCESS;
	}
}

class rcube_20i_restapi {
    private $bearerToken;
    private function sendRequest($url, $options = [])
    {
        $original_headers = isset($options[CURLOPT_HTTPHEADER]) ?
            $options[CURLOPT_HTTPHEADER] :
            [];
        unset($options[CURLOPT_HTTPHEADER]);
        $ch = curl_init($url);
        curl_setopt_array($ch, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $original_headers + [
                "Expect:",
                // ^Otherwise Curl will add Expect: 100 Continue, which is wrong.
                "Authorization: Bearer " . base64_encode($this->bearerToken),
            ],
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            throw new \Exception("Curl error: " . curl_error($ch));
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (preg_match('/^404/', $status)) {
            trigger_error("404 on $url");
            $response = null;
        } elseif (preg_match('/^[45]/', $status)) {
            throw new \Exception("HTTP error {$status} on {$url}");
        }

        curl_close($ch);
        return $response;
    }
    public function __construct($bearer_token)
    {
        $this->bearerToken = $bearer_token;
    }
    public function getRawWithFields($url, $fields = [], $options = [])
    {
        if (count($fields) > 0) {
            $query = array_reduce(
                array_keys($fields),
                function ($carry, $item) use ($fields) {
                    return ($carry ? "$carry&" : "?") .
                        urlencode($item) . "=" . urlencode($fields[$item]);
                },
                ""
            );
        } else {
            $query = "";
        }

        return $this->sendRequest($url . $query, $options);
    }
    public function getWithFields($url, $fields = [], $options = [])
    {
        $response = $this->getRawWithFields($url, $fields, $options);
        return json_decode($response);
    }
    public function postWithFields($url, $fields, $options = [])
    {
        $original_headers = isset($options[CURLOPT_HTTPHEADER]) ?
            $options[CURLOPT_HTTPHEADER] :
            [];
        unset($options[CURLOPT_HTTPHEADER]);
        $response = $this->sendRequest($url, [
            CURLOPT_HTTPHEADER => $original_headers + [
                "Content-Type: application/json",
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($fields),
        ] + $options);
        return json_decode($response);
    }
}
