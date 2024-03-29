<?php
/**
 * StatusNet, the distributed open-source microblogging tool
 *
 * Public tag cloud for notices
 *
 * PHP version 5
 *
 * LICENCE: This program is free software: you can redistribute it and/or modify
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
 *
 * @category  Public
 * @package   StatusNet
 * @author    Mike Cochrane <mikec@mikenz.geek.nz>
 * @author    Evan Prodromou <evan@status.net>
 * @copyright 2008 Mike Cochrane
 * @copyright 2008-2009 StatusNet, Inc.
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://status.net/
 */

if (!defined('STATUSNET') && !defined('LACONICA')) { exit(1); }

define('TAGS_PER_PAGE', 100);

/**
 * Public tag cloud for notices
 *
 * @category Personal
 * @package  StatusNet
 * @author    Mike Cochrane <mikec@mikenz.geek.nz>
 * @author    Evan Prodromou <evan@status.net>
 * @copyright 2008 Mike Cochrane
 * @copyright 2008-2009 StatusNet, Inc.
 * @link     http://status.net/
 */

class PublictagcloudAction extends Action
{
    function isReadOnly($args)
    {
        return true;
    }

    function title()
    {
        return _('Public tag cloud');
    }

    function showPageNotice()
    {
        $this->element('p', 'instructions',
                       sprintf(_('These are most popular recent tags on %s '),
                               common_config('site', 'name')));
    }

    function showEmptyList()
    {
        $message = _('No one has posted a notice with a [hashtag](%%doc.tags%%) yet.') . ' ';

        if (common_logged_in()) {
            $message .= _('Be the first to post one!');
        }
        else {
            $message .= sprintf(_('Why not [register an account](%%%%action.%s%%%%) and be the first to post one!'),
                                (!common_config('site','openidonly')) ? 'register' : 'openidlogin');
        }

        $this->elementStart('div', 'guide');
        $this->raw(common_markup_to_html($message));
        $this->elementEnd('div');
    }

    function showLocalNav()
    {
        $nav = new PublicGroupNav($this);
        $nav->show();
    }

    function handle($args)
    {
        parent::handle($args);
        $this->showPage();
    }

    function showContent()
    {
        # This should probably be cached rather than recalculated
        $tags = new Notice_tag();

        #Need to clear the selection and then only re-add the field
        #we are grouping by, otherwise it's not a valid 'group by'
        #even though MySQL seems to let it slide...
        $tags->selectAdd();
        $tags->selectAdd('tag');

        #Add the aggregated columns...
        $tags->selectAdd('max(notice_id) as last_notice_id');
        if(common_config('db','type')=='pgsql') {
            $calc='sum(exp(-extract(epoch from (now()-created))/%s)) as weight';
        } else {
            $calc='sum(exp(-(now() - created)/%s)) as weight';
        }
        $tags->selectAdd(sprintf($calc, common_config('tag', 'dropoff')));
        $tags->groupBy('tag');
        $tags->orderBy('weight DESC');

        $tags->limit(TAGS_PER_PAGE);

        $cnt = $tags->find();

        if ($cnt > 0) {
            $this->elementStart('div', array('id' => 'tagcloud',
                                             'class' => 'section'));

            $tw = array();
            $sum = 0;
            while ($tags->fetch()) {
                $tw[$tags->tag] = $tags->weight;
                $sum += $tags->weight;
            }

            ksort($tw);

            $this->elementStart('dl');
            $this->element('dt', null, _('Tag cloud'));
            $this->elementStart('dd');
            $this->elementStart('ul', 'tags xoxo tag-cloud');
            foreach ($tw as $tag => $weight) {
                $this->showTag($tag, $weight, $weight/$sum);
            }
            $this->elementEnd('ul');
            $this->elementEnd('dd');
            $this->elementEnd('dl');
            $this->elementEnd('div');
        } else {
            $this->showEmptyList();
        }
    }

    function showTag($tag, $weight, $relative)
    {
        if ($relative > 0.1) {
            $rel =  'tag-cloud-7';
        } else if ($relative > 0.05) {
            $rel = 'tag-cloud-6';
        } else if ($relative > 0.02) {
            $rel = 'tag-cloud-5';
        } else if ($relative > 0.01) {
            $rel = 'tag-cloud-4';
        } else if ($relative > 0.005) {
            $rel = 'tag-cloud-3';
        } else if ($relative > 0.002) {
            $rel = 'tag-cloud-2';
        } else {
            $rel = 'tag-cloud-1';
        }

        $this->elementStart('li', $rel);
        $this->element('a', array('href' => common_local_url('tag', array('tag' => $tag))),
                       $tag);
        $this->elementEnd('li');
    }
}
