<?php
/**
 * Created by PhpStorm.
 * User: Youi
 * Date: 2015-12-09
 * Time: 21:53
 */
class handler {

    private static function parseUrlParam($url) {
        if (preg_match('<https?://(.+)/post/(\d+)>', $url, $match)) {
            return array(
                'post_domain' => $match[1],
                'post_id'     => $match[2]
            );
        } else {
            return false;
        }
    }

    public static function handle($url) {

        $postParam = static::parseUrlParam($url);

        try {

            if (!$postParam) {
                $errMsg = "No a valid tumblr URL: $url";
                throw new Exception($errMsg);
            } else {
                $postInfo = Input::fetchQuickResponseInfoFromCache($postParam);
                if ($postInfo) {
                    //make quick response
                    switch ($postInfo['type']) {
                        case 'html':
                            Output::echoHtmlFile($postInfo['content']);
                            break;
                        case 'video':
                        case 'singlePhoto':
                            Output::redirect($postInfo['content']);
                            break;
                        case 'error':
                            Output::echoTxtFile($postInfo['content']);
                            break;
                    }

                    return true;
                }
            }

            $postJSON = Input::fetchPostInfoFromCache($postParam);
            !$postJSON && ($postJSON = Input::queryTumblrApi($postParam));
            if (!$postJSON) {
                $errMsg = 'No post info back from Tumblr';
                throw new Exception($errMsg);
            } else {
                //save post info to memcached
                Output::writePostInfoToCache($postParam, $postJSON);
            }

            $postType = Content::parsePostType($postJSON);
            $parserName = 'parse' . ucfirst($postType);
            $recordForNextTime = null;

            switch ($postType) {
                case 'answer':
                case 'link':
                case 'regular':
                case 'quote':
                    $output = Content::$parserName($postJSON);
                    Output::echoHtmlFile($output);
                    $recordForNextTime = array(
                        'type' => 'html',
                        'content' => $output
                    );
                    break;
                case 'video':

                    $output = Content::$parserName($postJSON);
                    if (!$output) {
                        $errMsg = "Can't not parse video post, maybe it's too complicated to get the video source location out.\r\n$url";
                        throw new Exception($errMsg);
                    } else {
                        Output::redirect($output);
                        $recordForNextTime = array(
                            'type' => 'video',
                            'content' => $output
                        );
                    }

                    break;
                case 'unknow':
                case 'photo':

                    $photoUrls = Content::$parserName($postJSON);
                    $photoCount = $photoUrls['count'];

                    if ($photoCount === 0) {

                        $errMsg = "No images found in the tumblr post: $url";
                        throw new Exception($errMsg);

                    } elseif ($photoCount === 1) {
                        Output::redirect($photoUrls[0]);

                        $recordForNextTime = array(
                            'type' => 'singlePhoto',
                            'content' => $photoUrls[0]
                        );

                    } else {

                        $imagesFromCache = Input::fetchImagesFromCache($photoUrls);

                        $images = array_fill_keys($photoUrls, null);
                        $randomUrls = array_values($photoUrls); shuffle($randomUrls);
                        foreach ($randomUrls as $photoUrl) {

                            $fileName = basename($photoUrl);
                            $temp = null;

                            if (isset($imagesFromCache[$fileName])) {

                                $temp = $imagesFromCache[$fileName];

                            } else {

                                $imageFromNetwork = Input::fetchImageFromNetwork($photoUrl);
                                $imageFromNetwork && ($temp = $imageFromNetwork);

                            }

                            $temp && ($images[$photoUrl] = $temp);
                        }
                        $images = array_filter($images);

                        Output::writeImagesToCache($images);

                        $zipPack = Content::getImagesZipPack($images);
                        Output::echoZipFile($zipPack);

                    }
                    break;

            }

            $recordForNextTime && Output::writeQuickResponseInfoToCache($postParam, $recordForNextTime);

        } catch (Exception $e) {

            $errText = Content::getErrorText($e->getMessage());

            if ($postParam) {

                $recordForNextTime = array(
                    'type' => 'error',
                    'content' => $errText
                );

                Output::writeQuickResponseInfoToCache($postParam, $recordForNextTime);
            }


            Output::echoTxtFile($errText);

        }

        return true;
    }

}