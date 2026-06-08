<?php

namespace Flyokai\MagentoAmpMate\Rewrite;

use Amp\File\File as AmpFile;
use Amp\File\FilesystemException;
use Flyokai\MagentoAmpMate\MageCache\Data\AmpStreamReader as CacheDataStreamReader;
use function Amp\ByteStream\buffer as ampStreamBuffer;
use function Amp\File\createDirectoryRecursively as ampCreateDirectoryRecursively;
use function Amp\File\getSize as ampFileSize;
use function Amp\File\isDirectory as ampIsDirectory;
use function Amp\File\isFile as ampIsFile;
use function Amp\File\listFiles as ampListFiles;
use function Amp\File\read as ampFileRead;
use function Flyokai\AmpMate\ampChmod;
use function Flyokai\AmpMate\ampDirExists;
use function Flyokai\AmpMate\ampFileExists;
use function Flyokai\AmpMate\ampFlock;
use function Flyokai\AmpMate\ampMkdir;
use function Flyokai\AmpMate\ampOpenFile;
use function Flyokai\AmpMate\ampUnlink;

class CmCacheBackendFile extends \Cm_Cache_Backend_File
{
    protected function _get($dir, $mode, $tags = array())
    {
        if (!ampIsDirectory($dir)) {
            return false;
        }
        $result = array();
        $prefix = $this->_options['file_name_prefix'];
        $dirFiles = ampListFiles($dir);
        foreach ($dirFiles as $file)  {
            if (!fnmatch($dir.$prefix . '--*', $file)) {
                continue;
            }
            if (ampIsFile($file)) {
                $fileName = basename($file);
                $id = $this->_fileNameToId($fileName);
                $metadatas = $this->_getMetadatas($id);
                if ($metadatas === FALSE) {
                    continue;
                }
                if (time() > $metadatas['expire']) {
                    continue;
                }
                switch ($mode) {
                    case 'ids':
                        $result[] = $id;
                        break;
                    case 'tags':
                        $result = array_unique(array_merge($result, $metadatas['tags']));
                        break;
                    case 'matching':
                        $matching = true;
                        foreach ($tags as $tag) {
                            if (!in_array($tag, $metadatas['tags'])) {
                                $matching = false;
                                break;
                            }
                        }
                        if ($matching) {
                            $result[] = $id;
                        }
                        break;
                    case 'notMatching':
                        $matching = false;
                        foreach ($tags as $tag) {
                            if (in_array($tag, $metadatas['tags'])) {
                                $matching = true;
                                break;
                            }
                        }
                        if (!$matching) {
                            $result[] = $id;
                        }
                        break;
                    case 'matchingAny':
                        $matching = false;
                        foreach ($tags as $tag) {
                            if (in_array($tag, $metadatas['tags'])) {
                                $matching = true;
                                break;
                            }
                        }
                        if ($matching) {
                            $result[] = $id;
                        }
                        break;
                    default:
                        \Zend_Cache::throwException('Invalid mode for _get() method');
                        break;
                }
            }
            if ((ampIsDirectory($file)) and ($this->_options['hashed_directory_level']>0)) {
                // Recursive call
                $recursiveRs =  $this->_get($file . DIRECTORY_SEPARATOR, $mode, $tags);
                if ($recursiveRs === false) {
                    $this->_log('Zend_Cache_Backend_File::_get() / recursive call : can\'t list entries of "'.$file.'"');
                } else {
                    $result = array_unique(array_merge($result, $recursiveRs));
                }
            }
        }
        return array_unique($result);
    }

    protected function _getCache($file, $withData)
    {
        if (!ampIsFile($file) || ! ($ampFile = ampOpenFile($file, 'rb'))) {
            return false;
        }
        if ($this->_options['file_locking']) {
            ampFlock($ampFile, LOCK_SH);
        }
        $reader = new CacheDataStreamReader($ampFile);
        $metadata = $reader->getMeta();
        if (! $metadata) {
            if ($this->_options['file_locking']) {
                ampFlock($ampFile, LOCK_UN);
            }
            $ampFile->close();
            return false;
        }
        if ($withData) {
            $data = $reader->getData();
        }
        if ($this->_options['file_locking']) {
            ampFlock($ampFile, LOCK_UN);
        }
        $ampFile->close();
        $metadata = @unserialize(rtrim($metadata, "\n"), ['allowed_classes' => false]);
        if ($metadata === false) {
            return false;
        }
        if ($withData) {
            return array($metadata, $data);
        }
        return $metadata;
    }

    public function save($data, $id, $tags = array(), $specificLifetime = false)
    {
        $file = $this->_file($id);
        $path = $this->_path($id);
        if ($this->_options['hashed_directory_level'] > 0) {
            try {
                $this->_recursiveMkdirAndChmod($id);
            } catch (FilesystemException $e) {
                return false;
            }
        }
        if ($this->_options['read_control']) {
            $hash = $this->_hash($data, $this->_options['read_control_type']);
        } else {
            $hash = '';
        }
        $metadatas = array(
            'hash' => $hash,
            'mtime' => time(),
            'expire' => $this->_expireTime($this->getLifetime($specificLifetime)),
            'tags' => implode(',', $tags),
        );
        $res = $this->_filePutContents($file, serialize($metadatas)."\n".$data);
        $res = $res && $this->_updateIdsTags(array($id), $tags, 'merge');
        return $res;
    }

    protected function _recursiveMkdirAndChmod($id)
    {
        if ($this->_options['hashed_directory_level'] <= 0) {
            return true;
        }
        $path = $this->_path($id);
        try {
            $mode = $this->_options['use_chmod'] ? $this->_options['directory_mode'] : 0777;
            ampCreateDirectoryRecursively($path, $mode);
        } catch (FilesystemException $e) {
            return false;
        }
        return true;
    }

    protected function _filePutContents($file, $string)
    {
        try {
            $ampFile = \Amp\File\openFile($file, 'w');
            if ($this->_options['file_locking']) {
                ampFlock($ampFile, LOCK_EX);
            }
            $ampFile->write($string);
            if ($this->_options['file_locking']) {
                ampFlock($ampFile, LOCK_UN);
            }
            $ampFile->close();
            if ($this->_options['use_chmod']) {
                ampChmod($file, $this->_options['file_mode']);
            }
        } catch (FilesystemException $e) {
            return false;
        }
        return strlen($string);
    }

    public function getTags()
    {
        $prefix = $this->_tagFile('');
        $prefixLen = strlen($prefix);
        $tags = array();
        $dir = $this->_options['cache_dir'];
        $dirFiles = ampListFiles($dir);
        foreach ($dirFiles as $tagFile) {
            if (!fnmatch($dir.$prefix.'*', $tagFile)) {
                continue;
            }
            $tags[] = substr($tagFile, $prefixLen);
        }
        return $tags;
    }

    protected function _clean($dir, $mode = \Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        try {
            return $this->_ampClean($dir, $mode, $tags);
        } catch (FilesystemException $e) {
            return false;
        }
    }

    protected function _ampClean($dir, $mode = \Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        if (!ampIsDirectory($dir)) {
            return false;
        }
        if ($mode == 'all' && $dir === $this->_options['cache_dir']) {
            $dirFiles = ampListFiles($dir);
            foreach ($dirFiles as $tagFile) {
                if (!fnmatch($dir.$this->_tagFile('*'), $tagFile)) {
                    continue;
                }
                ampUnlink($tagFile);
            }
        }
        $result = true;
        $dirFiles = ampListFiles($dir);
        foreach ($dirFiles as $file) {
            if (!fnmatch($dir.$this->_options['file_name_prefix'] . '--*', $file)) {
                continue;
            }
            if (ampIsFile($file)) {
                if ($mode == \Zend_Cache::CLEANING_MODE_ALL) {
                    $result = ampUnlink($file) && $result;
                    continue;
                }

                $id = $this->_fileNameToId(basename($file));
                $_file = $this->_file($id);
                if ($file != $_file) {
                    ampUnlink($file);
                    continue;
                }
                $metadatas = $this->_getCache($file, false);
                if (! $metadatas) {
                    ampUnlink($file);
                    continue;
                }
                if ($mode == \Zend_Cache::CLEANING_MODE_OLD) {
                    if (time() > $metadatas['expire']) {
                        $result = $this->_remove($file) && $result;
                        $result = $this->_updateIdsTags(array($id), explode(',', $metadatas['tags']), 'diff') && $result;
                    }
                    continue;
                } else {
                    \Zend_Cache::throwException('Invalid mode for clean() method.');
                }
            }
            if (is_dir($file) && $this->_options['hashed_directory_level'] > 0) {
                // Recursive call
                $result = $this->_clean($file . DIRECTORY_SEPARATOR, $mode) && $result;
                if ($mode == 'all') {
                    // if mode=='all', we try to drop the structure too
                    @rmdir($file);
                }
            }
        }
        return true;
    }

    protected function _remove($file)
    {
        if (!ampIsFile($file)) {
            return false;
        }
        if (!ampUnlink($file)) {
            # we can't remove the file (because of locks or any problem)
            $this->_log("Zend_Cache_Backend_File::_remove() : we can't remove $file");
            return false;
        }
        return true;
    }

    protected function _updateIdsTags($ids, $tags, $mode)
    {
        $result = true;
        if (empty($ids)) {
            return $result;
        }
        foreach($tags as $tag) {
            $file = $this->_tagFile($tag);
            if (ampFileExists($file)) {
                if ($mode == 'diff' || (mt_rand(1, 100) == 1 && ampFileSize($file) > 4096)) {
                    $file = $this->_tagFile($tag);
                    if (! ($ampFile = ampOpenFile($file, 'rb+'))) {
                        $result = false;
                        continue;
                    }
                    if ($this->_options['file_locking']) {
                        ampFlock($ampFile, LOCK_EX);
                    }
                    if ($mode == 'diff') {
                        $_ids = array_diff($this->_getTagIds($ampFile), $ids);
                    } else {
                        $_ids = array_merge($this->_getTagIds($ampFile), $ids);
                    }
                    $ampFile->seek(0);
                    $ampFile->truncate(0);
                    $result = $ampFile->write(implode("\n", array_unique($_ids))."\n") && $result;
                    if ($this->_options['file_locking']) {
                        ampFlock($ampFile, LOCK_UN);
                    }
                    $ampFile->close();
                } else {
                    if (!($ampFile = ampOpenFile($file, 'a'))) {
                        $result = false;
                        continue;
                    }
                    if ($this->_options['file_locking']) {
                        ampFlock($ampFile, LOCK_EX);
                    }
                    $ampFile->write(implode("\n", $ids) . "\n");
                    if ($this->_options['file_locking']) {
                        ampFlock($ampFile, LOCK_UN);
                    }
                    $ampFile->close();
                    $result = $result && $result;
                }
            } elseif ($mode == 'merge') {
                $result = $this->_filePutContents($file, implode("\n", $ids)."\n") && $result;
            }
        }
        return $result;
    }

    protected function _getIdsByTags($mode, $tags, $delete)
    {
        $ids = array();
        switch($mode) {
            case \Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
                $ids = $this->getIds();
                if ($tags) {
                    foreach ($tags as $tag) {
                        if (! $ids) {
                            break; // early termination optimization
                        }
                        $ids = array_diff($ids, $this->_getTagIds($tag));
                    }
                }
                break;
            case \Zend_Cache::CLEANING_MODE_MATCHING_TAG:
                if ($tags) {
                    $tag = array_shift($tags);
                    $ids = $this->_getTagIds($tag);
                    foreach ($tags as $tag) {
                        if (! $ids) {
                            break; // early termination optimization
                        }
                        $ids = array_intersect($ids, $this->_getTagIds($tag));
                    }
                    $ids = array_unique($ids);
                }
                break;
            case \Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
                foreach ($tags as $tag) {
                    $file = $this->_tagFile($tag);
                    if (!ampIsFile($file) || ! ($ampFile = ampOpenFile($file, 'rb+'))) {
                        continue;
                    }
                    if ($this->_options['file_locking']) {
                        ampFlock($ampFile, LOCK_EX);
                    }
                    $ids = array_merge($ids, $this->_getTagIds($ampFile));
                    if ($delete) {
                        $ampFile->seek(0);
                        $ampFile->truncate(0);
                    }
                    if ($this->_options['file_locking']) {
                        ampFlock($ampFile, LOCK_UN);
                    }
                    $ampFile->close();
                }
                $ids = array_unique($ids);
                break;
        }
        return $ids;
    }

    protected function _getTagIds($tag)
    {
        if ($tag instanceof AmpFile) {
            $ids = ampStreamBuffer($tag);
        } elseif (is_resource($tag)) {
            $ids = ampStreamBuffer(new \Amp\ByteStream\ReadableResourceStream($tag));
        } elseif(ampFileExists($this->_tagFile($tag))) {
            $ids = ampFileRead($this->_tagFile($tag));
        } else {
            $ids = false;
        }
        if(! $ids) {
            return array();
        }
        $ids = trim(substr($ids, 0, strrpos($ids, "\n")));
        return $ids ? explode("\n", $ids) : array();
    }

    protected function _cleanNew($mode = \Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        $result = true;
        $ids = $this->_getIdsByTags($mode, $tags, true);
        switch($mode) {
            case \Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
            case \Zend_Cache::CLEANING_MODE_MATCHING_TAG:
                $this->_updateIdsTags($ids, $tags, 'diff');
                break;
        }
        foreach ($ids as $id) {
            $idFile = $this->_file($id);
            if (ampIsFile($idFile)) {
                $result = $this->_remove($idFile) && $result;
            }
        }
        return $result;
    }

    protected function _tagPath()
    {
        $path = $this->_options['cache_dir'] . DIRECTORY_SEPARATOR . $this->_options['file_name_prefix']. '-tags' . DIRECTORY_SEPARATOR;
        if (! $this->_isTagDirChecked) {
            if (!ampDirExists($path)) {
                if (ampMkdir($path, $this->_options['use_chmod'] ? $this->_options['directory_mode'] : 0777) && $this->_options['use_chmod']) {
                    ampChmod($path, $this->_options['directory_mode']); // see #ZF-320 (this line is required in some configurations)
                }
            }
            $this->_isTagDirChecked = true;
        }
        return $path;
    }

    protected function _fileGetContents($file)
    {
        $result = false;
        if (!ampIsFile($file)) {
            return false;
        }
        if (($ampFile = ampOpenFile($file, 'rb'))) {
            if ($this->_options['file_locking']) {
                ampFlock($ampFile, LOCK_SH);
            }
            $result = ampStreamBuffer($ampFile);
            if ($this->_options['file_locking']) {
                ampFlock($ampFile, LOCK_UN);
            }
            $ampFile->close();
        }
        return $result;
    }

}
