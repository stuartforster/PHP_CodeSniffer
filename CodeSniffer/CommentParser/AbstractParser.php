<?php
/**
 * +------------------------------------------------------------------------+
 * | BSD Licence                                                            |
 * +------------------------------------------------------------------------+
 * | This software is available to you under the BSD license,               |
 * | available in the LICENSE file accompanying this software.              |
 * | You may obtain a copy of the License at                                |
 * |                                                                        |
 * | http://matrix.squiz.net/developer/tools/php_cs/licence                 |
 * |                                                                        |
 * | THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS    |
 * | "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT      |
 * | LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR  |
 * | A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT   |
 * | OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,  |
 * | SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT       |
 * | LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,  |
 * | DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY  |
 * | THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT    |
 * | (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE  |
 * | OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.   |
 * +------------------------------------------------------------------------+
 * | Copyright (c), 2006 Squiz Pty Ltd (ABN 77 084 670 600).                |
 * | All rights reserved.                                                   |
 * +------------------------------------------------------------------------+
 *
 * @package  PHP_CodeSniffer
 * @category CommentParser
 * @author   Squiz Pty Ltd
 */

require_once 'PHP/CodeSniffer/CommentParser/SingleElement.php';
require_once 'PHP/CodeSniffer/CommentParser/CommentElement.php';
require_once 'PHP/CodeSniffer/CommentParser/ParserException.php';

/**
 * Parses doc comments.
 *
 * This abstract parser handles the following tags:
 *
 * <ul>
 *  <li>The short description and the long description</li>
 *  <li>@see</li>
 *  <li>@link</li>
 *  <li>@deprecated</li>
 *  <li>@since</li>
 * </ul>
 *
 * Extending classes should implement the getAllowedTags() method to return the
 * tags that they wish to process, ommiting the tags that this base class
 * processes. When one of these tags in encountered, the process&lt;tag_name&gt;
 * method is called on that class. For example, if a parser's getAllowedTags()
 * method returns \@param as one of its tags, the processParam method will be
 * called so that the parser can process such a tag.
 *
 * The method is passed the tokens that comprise this tag. The tokens array
 * includes the whitespace that exists between the tokens, as seperate tokens.
 * It's up to the method to create a element that implements the DocElement
 * interface, which should be returned. The AbstractDocElement class is a helper
 * class that can be used to handle most of the parsing of the tokens into their
 * individual sub elements. It requires that you construct it with the element
 * previous to the element currently being processed, which can be acquired
 * with the protected $previousElement class member of this class.
 *
 * @package  PHP_CodeSniffer
 * @category CommentParser
 * @author   Squiz Pty Ltd
 */
abstract class PHP_CodeSniffer_CommentParser_AbstractParser
{

    /**
     * The comment element that appears in the doc comment.
     *
     * @var PHP_CodeSniffer_CommentParser_CommentElement
     */
    protected $comment = null;

    /**
     * The word tokens that appear in the comment.
     *
     * Whitespace tokens also appear in this stack, but are separate tokens
     * from words.
     *
     * @var array(string)
     */
    protected $words = array();

    /**
     * The previous doc element that was processed.
     *
     * null if the current element being processed is the first element in the
     * doc comment.
     *
     * @var PHP_CodeSniffer_CommentParser_DocElement
     */
    protected $previousElement = null;

    /**
     * A list of see elements that appear in this doc comment.
     *
     * @var array(PHP_CodeSniffer_CommentParser_SingleElement)
     */
    protected $sees = array();

    /**
     * A list of see elements that appear in this doc comment.
     *
     * @var array(PHP_CodeSniffer_CommentParser_SingleElement)
     */
    protected $deprecated = null;

    /**
     * A list of see elements that appear in this doc comment.
     *
     * @var array(PHP_CodeSniffer_CommentParser_SingleElement)
     */
    protected $links = array();

    /**
     * A element to represent \@since tags.
     *
     * @var PHP_CodeSniffer_CommentParser_SingleElement
     */
    protected $since = null;

    /**
     * True if the comment has been parsed.
     *
     * @var boolean
     */
    private $_hasParsed = false;

    /**
     * The tags that this class can process.
     *
     * @var array(string)
     */
    private static $_tags = array(
                             'see'        => false,
                             'link'       => false,
                             'deprecated' => true,
                             'since'      => true,
                            );


    /**
     * Constructs a Doc Comment Parser.
     *
     * @param string $comment The comment to parse.
     */
    public function __construct($comment)
    {
        $this->_comment = $comment;

    }//end __construct()


    /**
     * Initiates the parsing of the doc comment.
     *
     * @return void
     * @throws PHP_CodeSniffer_CommentParser_ParserException If the parser finds an
     *                                                       anomilty with the comment.
     */
    public function parse()
    {
        if ($this->_hasParsed === false) {
            $this->_parse($this->_comment);
        }

    }//end parse()


    /**
     * Parse the comment.
     *
     * @param string $comment The doc comment to parse.
     *
     * @return void
     * @see _parseWords()
     */
    private function _parse($comment)
    {
        // Firstly, remove the comment tags and any stars from the left side.
        $lines = split("\n", $comment);
        foreach ($lines as &$line) {
            $line = trim($line);

            if (substr($line, 0, 3) === '/**') {
                $line = substr($line, 3);
            } else if (substr($line, -2, 2) === '*/') {
                $line = substr($line, 0, -2);
            } else if ($line{0} === '*') {
                $line = substr($line, 1);
            }

            // Add the words to the stack, preserving newlines. Other parsers
            // might be interested in the spaces between words, so tokenize
            // spaces as well as separate tokens.
            $flags = (PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            $words = preg_split('|(\s+)|', $line."\n", -1, $flags);
            $this->words = array_merge($this->words, $words);
        }

        $this->_parseWords();

    }//end _parse()


    /**
     * Parses each word within the doc comment.
     *
     * @return void
     * @see _parse()
     * @throws PHP_CodeSniffer_CommentParser_ParserException If more than the allowed number of
     *                                                       occurances of a tag is found.
     */
    private function _parseWords()
    {
        $allowedTags     = (self::$_tags + $this->getAllowedTags());
        $allowedTagNames = array_keys($allowedTags);

        $foundTags = array();

        $prevTagPos    = false;
        $wordsWasEmpty = true;

        foreach ($this->words as $wordPos => $word) {

            if (trim($word) !== '') {
                $wordsWasEmpty = false;
            }

            if ($word{0} === '@') {
                $tag = substr($word, 1);
                // Check to see if this is a known tag for this parser.
                if (in_array($tag, $allowedTagNames) === true) {

                    if ($allowedTags[$tag] === true && in_array($tag, $foundTags) === true) {
                        $exception = 'Only one occurence of the @'.$tag.' is allowed';
                        $line      = $this->getLine($wordPos);
                        throw new PHP_CodeSniffer_CommentParser_ParserException($exception, $line);
                    }

                    $foundTags[] = $tag;

                    if ($prevTagPos !== false) {
                        // There was a tag before this so let's process it.
                        $prevTag = substr($this->words[$prevTagPos], 1);
                        $this->parseTag($prevTag, $prevTagPos, $wordPos - 1);
                    } else {
                        // There must have been a comment before this tag, so
                        // let's process that.
                        $this->parseTag('comment', 0, $wordPos - 1);
                    }

                    $prevTagPos = $wordPos;
                }//end if
            }//end if
        }//end foreach

        // Only process this tag if their was something to process.
        if ($wordsWasEmpty === false) {
            if ($prevTagPos === false) {
                // There must only be a comment in this doc comment.
                $this->parseTag('comment', 0, count($this->words));
            } else {
                // Process the last tag element.
                $prevTag = substr($this->words[$prevTagPos], 1);
                $this->parseTag($prevTag, $prevTagPos, count($this->words));
            }
        }

    }//end _parseWords()


    /**
     * Returns the line that the token exists on in the doc comment.
     *
     * @param int $tokenPos The position in the words stack to find the line
     *                      number for.
     *
     * @return int
     */
    protected function getLine($tokenPos)
    {
        $newlines = 1;
        for ($i = 0; $i < $tokenPos; $i++) {
            $newlines += substr_count("\n", $this->words[$i]);
        }

        return $newlines;

    }//end getLine()


    /**
     * Parses see tag element within the doc comment.
     *
     * @param array(string) $tokens The word tokens that comprise this element.
     *
     * @return DocElement The element that represents this see comment.
     */
    protected function parseSee($tokens)
    {
        $see = new PHP_CodeSniffer_CommentParser_SingleElement($this->previousElement, $tokens, 'see');
        $this->sees[] = $see;
        return $see;

    }//end parseSee()


    /**
     * Parses the comment element that appears at the top of the doc comment.
     *
     * @param array(string) $tokens The word tokens that comprise tihs element.
     *
     * @return DocElement The element that represents this comment element.
     */
    protected function parseComment($tokens)
    {
        $this->comment = new PHP_CodeSniffer_CommentParser_CommentElement($this->previousElement, $tokens);
        return $this->comment;

    }//end parseComment()


    /**
     * Parses \@deprecated tags.
     *
     * @param array(string) $tokens The word tokens that comprise tihs element.
     *
     * @return DocElement The element that represents this deprecated tag.
     */
    protected function parseDeprecated($tokens)
    {
        $this->deprecated = new PHP_CodeSniffer_CommentParser_SingleElement($this->previousElemement, $tokens, 'deprecated');
        return $this->deprecated;

    }//end parseDeprecated()


    /**
     * Parses \@since tags.
     *
     * @param array(string) $tokens The word tokens that comprise this element.
     *
     * @return SingleElement The element that represents this since tag.
     */
    protected function parseSince($tokens)
    {
        $this->since = new PHP_CodeSniffer_CommentParser_SingleElement($this->previousElement, $tokens, 'since');
        return $this->since;

    }//end parseSince()


    /**
     * Parses \@link tags.
     *
     * @param array(string) $tokens The word tokens that comprise this element.
     *
     * @return SingleElement The element that represents this link tag.
     */
    protected function parseLink($tokens)
    {
        $link = new PHP_CodeSniffer_CommentParser_SingleElement($this->previousElement, $tokens, 'link');
        $this->links[] = $link;
        return $link;

    }//end parseLink()


    /**
     * Returns the see elements that appear in this doc comment.
     *
     * @return array(SingleElement)
     */
    public function getSees()
    {
        return $this->sees;

    }//end getSees()


    /**
     * Returns the comment element that appears at the top of this doc comment.
     *
     * @return CommentElement
     */
    public function getComment()
    {
        return $this->comment;

    }//end getComment()


    /**
     * Returns the link elements found in this comment.
     *
     * Returns an empty array if no links are found in the comment.
     *
     * @return array(SingleElement)
     */
    public function getLinks()
    {
        return $this->links;

    }//end getLinks()


    /**
     * Returns the deprecated element found in this comment.
     *
     * Returns null if no element exists in the comment.
     *
     * @return SingleElement
     */
    public function getDeprecated()
    {
        return $this->deprecated;

    }//end getDeprecated()


    /**
     * Returns the since element found in this comment.
     *
     * Returns null if no element exists in the comment.
     *
     * @return SingleElement
     */
    public function getSince()
    {
        return $this->since;

    }//end getSince()


    /**
     * Parses the specified tag.
     *
     * @param string $tag   The tag name to parse (omitting the @ sybmol from
     *                      the tag)
     * @param int    $start The position in the word tokens where this element
     *                      started.
     * @param int    $end   The position in the word tokens where this element
     *                      ended.
     *
     * @return void
     * @throws Exception If the process method for the tag cannot be found.
     */
    protected function parseTag($tag, $start, $end)
    {
        $method = 'parse'.$tag;
        $tokens = array_slice($this->words, $start + 1, $end - $start);

        if (method_exists($this, $method) === false) {
            $error = 'Method '.$method.' must be implemented to process '.$tag.' tags';
            throw new Exception($error);
        }

        $this->previousElement = $this->$method($tokens);

        if ($this->previousElement === null || ($this->previousElement instanceof PHP_CodeSniffer_CommentParser_DocElement) === false) {
            throw new Exception('Parse method must return a DocElement');
        }

    }//end parseTag()


    /**
     * Returns a list of tags that this comment parser allows for it's comment.
     *
     * Each tag should indicate if only one entry of this tag can exist in the
     * comment by specifying true as the array value, or false if more than one
     * is allowed. Each tag should ommit the @ symbol. Only tags other than
     * the standard tags should be returned.
     *
     * @return array(string => boolean)
     */
    protected abstract function getAllowedTags();


}//end class

?>