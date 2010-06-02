<?php
/**
 * SimpleSearch
 *
 * Copyright 2010 by Shaun McCormick <shaun@modxcms.com>
 *
 * This file is part of SimpleSearch, a simple search component for MODx
 * Revolution. It is loosely based off of AjaxSearch for MODx Evolution by
 * coroico/kylej, minus the ajax.
 *
 * SimpleSearch is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * SimpleSearch is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details.
 *
 * You should have received a copy of the GNU General Public License along with
 * SimpleSearch; if not, write to the Free Software Foundation, Inc., 59 Temple Place,
 * Suite 330, Boston, MA 02111-1307 USA
 *
 * @package simplesearch
 */
/**
 * The base class for SimpleSearch
 *
 * @package 
 */
class SimpleSearch {
    public $modx;
    public $config = array();
    public $searchString = '';
    public $searchArray = array();
    public $ids = '';

    function __construct(modX &$modx,array $config = array()) {
    	$this->modx =& $modx;
        $corePath = $modx->getOption('sisea.core_path',null,$modx->getOption('core_path').'components/simplesearch/');
        $assetsUrl = $modx->getOption('sisea.assets_url',null,$modx->getOption('assets_url').'components/simplesearch/');
        
        $this->config = array_merge(array(
            'corePath' => $corePath,
            'chunksPath' => $corePath.'elements/chunks/',
        ),$config);
        $this->modx->lexicon->load('sisea:default');
    }

    /**
     * Gets a Chunk and caches it; also falls back to file-based templates
     * for easier debugging.
     *
     * @access public
     * @param string $name The name of the Chunk
     * @param array $properties The properties for the Chunk
     * @return string The processed content of the Chunk
     */
    public function getChunk($name,$properties = array()) {
        $chunk = null;
        if (!isset($this->chunks[$name])) {
            $chunk = $this->_getTplChunk($name);
            if (empty($chunk)) {
                $chunk = $this->modx->getObject('modChunk',array('name' => $name),true);
                if ($chunk == false) return false;
            }
            $this->chunks[$name] = $chunk->getContent();
        } else {
            $o = $this->chunks[$name];
            $chunk = $this->modx->newObject('modChunk');
            $chunk->setContent($o);
        }
        $chunk->setCacheable(false);
        return $chunk->process($properties);
    }

    /**
     * Returns a modChunk object from a template file.
     *
     * @access private
     * @param string $name The name of the Chunk. Will parse to name.chunk.tpl
     * @return modChunk/boolean Returns the modChunk object if found, otherwise
     * false.
     */
    private function _getTplChunk($name) {
        $chunk = false;
        $f = $this->config['chunksPath'].strtolower($name).'.chunk.tpl';
        if (file_exists($f)) {
            $o = file_get_contents($f);
            $chunk = $this->modx->newObject('modChunk');
            $chunk->set('name',$name);
            $chunk->setContent($o);
        }
        return $chunk;
    }
    
    /**
     * Parses search string and removes any potential security risks in the search string
     *
     * @param string $str The string to parse.
     * @return string The parsed and cleansed string.
     */
    public function parseSearchString($str = '') {
        $minChars = $this->modx->getOption('minChars',$this->config,4);
        
        $this->searchArray = explode(' ',$str);
        $this->searchArray = $this->modx->sanitize($this->searchArray, $this->modx->sanitizePatterns);
        foreach ($this->searchArray as $key => $term) {
            $this->searchArray[$key] = strip_tags($term);
            if (strlen($term) < $minChars) unset($this->searchArray[$key]);
        }
        $this->searchString = implode(' ', $this->searchArray);
        return $this->searchString;
    }

    /**
     * Gets a modResource collection that matches the search terms
     *
     * @param string $str The string to use to search with.
     * @return array An array of modResource results of the search.
     */
    public function getSearchResults($str = '') {
        if (!empty($str)) $this->searchString = $str;
        
        $ids = $this->modx->getOption('ids',$this->config,'');
        $useAllWords = $this->modx->getOption('useAllWords',$this->config,false);
        $searchStyle = $this->modx->getOption('searchStyle',$this->config,'partial');
        $hideMenu = $this->modx->getOption('hideMenu',$this->config,2);
        $maxWords = $this->modx->getOption('maxWords',$this->config,7);

    	$c = $this->modx->newQuery('modResource');
    	/* process conditional clauses */
        if ($searchStyle == 'partial') {
            $whereArray = array();
            if (empty($useAllWords)) {
                $i = 1;
                foreach ($this->searchArray as $term) {
                    if ($i > $maxWords) break;
                    $whereArray[] = array('pagetitle:LIKE', '%'.$term.'%',xPDOQuery::SQL_OR,1);
                    $whereArray[] = array('description:LIKE', '%'.$term.'%', xPDOQuery::SQL_OR, 1);
                    $whereArray[] = array('introtext:LIKE', '%'.$term.'%', xPDOQuery::SQL_OR, 1);
                    $whereArray[] = array('content:LIKE', '%'.$term.'%', xPDOQuery::SQL_OR, 1);
                    $i++;
                }
            } else {
                $whereArray[] = array('pagetitle:LIKE', '%'.$this->searchString.'%', xPDOQuery::SQL_OR, 1);
                $whereArray[] = array('description:LIKE', '%'.$this->searchString.'%', xPDOQuery::SQL_OR, 1);
                $whereArray[] = array('introtext:LIKE', '%'.$this->searchString.'%', xPDOQuery::SQL_OR, 1);
                $whereArray[] = array('content:LIKE', '%'.$this->searchString.'%', xPDOQuery::SQL_OR, 1);
            }
            foreach ($whereArray as $clause) {
                $c->where(array($clause[0] => $clause[1]), $clause[2], null, $clause[3]);
            }
    	} else {
            $fields = $this->modx->getSelectColumns('modResource', '', '', array('pagetitle', 'longtitle', 'description', 'introtext', 'content'));
            if (empty($useAllWords)) {
                $i = 0;
                foreach ($this->searchArray as $term) {
                    if ($i > $maxWords) break;
                    $term = $this->modx->quote($term);
                    $c->where("MATCH ( {$fields} ) AGAINST ( {$term} IN BOOLEAN MODE )");
                    $i++;
                }
            } else {
                $term = $this->modx->quote($str);
                $c->where("MATCH ( {$fields} ) AGAINST ( {$term} IN BOOLEAN MODE )");
            }
    	}
    	if (!empty($ids)) {
            $idType = $this->modx->getOption('idType',$this->config,'parents');
            $depth = $this->modx->getOption('depth',$this->config,10);
            $ids = $this->processIds($ids,$idType,$depth);
            $f = $this->modx->getSelectColumns('modResource','modResource','',array('id'));
            $c->where($f.' IN ('.$ids.')',xPDOQuery::SQL_AND,null,2);
        }
    	$c->where(array('published:=' => 1), xPDOQuery::SQL_AND, null, 2);
    	$c->where(array('searchable:=' => 1), xPDOQuery::SQL_AND, null, 2);
    	$c->where(array('deleted:=' => 0), xPDOQuery::SQL_AND, null, 2);

        /* restrict to either this context or specified contexts */
        $ctx = $this->modx->getOption('contexts',$this->config,$this->modx->context->get('key'));
        $f = $this->modx->getSelectColumns('modResource','modResource','',array('context_key'));
        $ctx = $this->prepareForIn($ctx);
    	$c->where($f.' IN ('.$ctx.')', xPDOQuery::SQL_AND, null, 2);
        if ($hideMenu != 2) {
            $c->where(array('hidemenu' => $hideMenu == 1 ? true : false));
        }
        $this->searchResultsCount = $this->modx->getCount('modResource', $c);
        
    	/* set limit */
        $perPage = $this->modx->getOption('perPage',$this->config,10);
    	if (!empty($perPage)) {
            $offset = $this->modx->getOption('start',$this->config,0);
            $offsetIndex = $this->modx->getOption('offsetIndex',$this->config,'sisea_offset');
            if (isset($_REQUEST[$offsetIndex])) $offset = $_REQUEST[$offsetIndex];
            $c->limit($perPage,$offset);
    	}
    	
        $this->docs = $this->modx->getCollection('modResource', $c);
        return $this->docs;
    }

    /**
     * Cleans a comma-separated list string for use in an IN clause
     *
     * @param string $csl The comma-separated list
     * @param string $delimiter The delimiter that separates items in the string
     * @return string The formatted string
     */
    protected function prepareForIn($csl,$delimiter = ',') {
        $cslArray = explode($delimiter,$csl);
        $results = array();
        foreach ($cslArray as $item) {
            $results[] = '"'.$item.'"';
        }
        return implode($delimiter,$results);
    }

    /**
     * Generates the pagination links
     *
     * @param integer $perPage The number of items per page
     * @param string $separator The separator to use between pagination links
     * @return string Pagination links.
     */
    public function getPagination($perPage = 10,$separator = ' | ') {
        $pagination = '';

        /* setup default properties */
        $searchIndex = $this->modx->getOption('searchIndex',$this->config,'search');
        $searchOffset = $this->modx->getOption('offsetIndex',$this->config,'sisea_offset');
        $pageTpl = $this->modx->getOption('pageTpl',$scriptProperties,'PageLink');
        $currentPageTpl = $this->modx->getOption('currentPageTpl',$scriptProperties,'CurrentPageLink');

        /* get search string */
        if (!empty($this->searchString)) {
            $searchString = urlencode($this->searchString);
        } else {
            $searchString = isset($_REQUEST[$searchIndex]) ? $_REQUEST[$searchIndex] : '';
        }

        $pageLinkCount = ceil($this->searchResultsCount / $perPage);
        for ($i = 0; $i < $pageLinkCount; ++$i) {
            $pageArray['text'] = $i+1;
            $pageArray['separator'] = $separator;
            if ($_GET[$searchOffset] == ($i * $perPage)) {
                $pageArray['link'] = $i+1;
                $pagination .= $this->getChunk($currentPageTpl,$pageArray);
            } else {
                $pageArray['link'] = $this->modx->makeUrl($this->modx->resource->get('id'), '', $searchOffset.'=' . ($i * $perPage) . '&'.$searchIndex.'=' .$searchString);
                $pagination .= $this->getChunk($pageTpl,$pageArray);
            }
            if ($i < $pageLinkCount) {
                $pagination .= $separator;
            }
        }
        return trim($pagination,$separator);
    }

    /**
     * Sanitize a string
     */
    public function sanitize($text) {
        $text = strip_tags($text);
        $text = preg_replace('/(\[\[\+.*?\]\])/i', '', $text);
        return $this->modx->stripTags($text);
    }

    /**
     * Create an extract from the passed text parameter
     *
     * @param string $text The text that the extract will be created from.
     * @param int $length The length of the extract to be generated.
     * @param string $search The search term to center the extract around.
     * @param string $ellipsis The ellipsis to use to wrap around the extract.
     * @return string The generated extract.
     */
    public function createExtract($text, $length = 200,$search = '',$ellipsis = '...') {
        $text = $this->sanitize($text);
        if (empty($text)) return '';

        if (empty($search)) {
            return substr($text,0,$length);
        }

        if (extension_loaded('mbstring')) {
            $wordpos = mb_strpos(mb_strtolower($text), mb_strtolower($search));
            $halfside = intval($wordpos - $length / 2 + mb_strlen($search) / 2);
            if ($wordpos && $halfside > 0) {
                $extract = $ellipsis . mb_substr($text, $halfside, $length) . $ellipsis;
            } else {
                $extract = mb_substr($text, 0, $length) . '...';
            }
        } else {
            $wordpos = strpos(strtolower($text), strtolower($search));
            $halfside = intval($wordpos - $length / 2 + strlen($search) / 2);
            if ($wordpos && $halfside > 0) {
                $extract = $ellipsis . substr($text, $halfside, $length) . $ellipsis;
            } else {
                $extract = substr($text, 0, $length) . $ellipsis;
            }
        }
        return $extract;
    }


    /**
     * Adds highlighting to the passed string
     *
     * @param string $string The string to be highlighted.
     * @param string $cls The CSS class to add to the tag wrapper
     * @param string $tag The type of HTML tag to wrap with
     * @return string The highlighted string
     */
    public function addHighlighting($string, $cls = 'sisea-highlight',$tag = 'span') {
        if (is_array($this->searchArray)) {
            foreach ($this->searchArray as $key => $value) {
                $string = preg_replace('/' . $value . '/i', '<'.$tag.' class="' . $cls . ' '.$class.($key+1).'">$0</'.$tag.'>', $string);
            }
        }
        return $string;
    }

    /**
     * Process the passed IDs
     *
     * @param string $ids The IDs to search
     * @param string $type The type of id filter
     * @param integer $depth The depth in the Resource tree to filter by
     * @return string Comma delimited string of the IDs
     */
    protected function processIds($ids = '',$type = 'parents',$depth = 10) {
        if (!strlen($ids)) return '';
        $ids = $this->cleanIds($ids);
    	switch ($type) {
            case 'parents':
                $idArray = explode(',', $ids);
                $ids = $idArray;
                foreach ($idArray as $id) {
                    $ids = array_merge($ids,$this->modx->getChildIds($id,$depth));
                }
                $ids = array_unique($ids);
                sort($ids);
                $ids = implode(',',$ids);
                break;
        }
        $this->ids = $ids;
        return $this->ids;
    }

    /**
     * Clean IDs
     *
     * @param string Comma delimited string of IDs
     * @return string Cleaned comma delimited string of IDs
     */
    public function cleanIds($ids) {
        $pattern = array (
            '`(,)+`', //Multiple commas
            '`^(,)`', //Comma on first position
            '`(,)$`' //Comma on last position
        );
        $replace = array (
            ',',
            '',
            ''
        );
        return preg_replace($pattern, $replace, $ids);
    }
}