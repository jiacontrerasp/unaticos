<?php
/**
 * Access token class.
 *
 * PHP version 5
 *
 * @category Action
 * @package  StatusNet
 * @author   Evan Prodromou <evan@status.net>
 * @author   Robin Millette <millette@status.net>
 * @license  http://www.fsf.org/licensing/licenses/agpl.html AGPLv3
 * @link     http://status.net/
 *
 * StatusNet - the distributed open-source microblogging tool
 * Copyright (C) 2008, 2009, StatusNet, Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

if (!defined('STATUSNET') && !defined('LACONICA')) {
    exit(1);
}

require_once INSTALLDIR.'/lib/omb.php';

/**
 * Access token class.
 *
 * @category Action
 * @package  StatusNet
 * @author   Evan Prodromou <evan@status.net>
 * @author   Robin Millette <millette@status.net>
 * @license  http://www.fsf.org/licensing/licenses/agpl.html AGPLv3
 * @link     http://status.net/
 */
class AccesstokenAction extends Action
{
    /**
     * Class handler.
     *
     * @param array $args query arguments
     *
     * @return boolean false if user doesn't exist
     */
    function handle($args)
    {
        parent::handle($args);
        try {
            common_debug('getting request from env variables', __FILE__);
            common_remove_magic_from_request();
            $req = OAuthRequest::from_request('POST', common_local_url('accesstoken'));
            common_debug('getting a server', __FILE__);
            $server = omb_oauth_server();
            common_debug('fetching the access token', __FILE__);
            $token = $server->fetch_access_token($req);
            common_debug('got this token: "'.print_r($token, true).'"', __FILE__);
            common_debug('printing the access token', __FILE__);
            print $token;
        } catch (OAuthException $e) {
            $this->serverError($e->getMessage());
        }
    }
}
