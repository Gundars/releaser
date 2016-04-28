<?php

namespace Releaser\Models;

class GithubAPIClient
{
    /**
     * Github API root
     */
    const GITHUB_ROOT = 'https://api.github.com/';

    /**
     * Github file encoding
     */
    const GITHUB_FILE_ENCODING = 'base64';


    private $token;
    private $owner;

    public function __construct($token, $owner)
    {
        $this->token = $token;
        $this->owner = $owner;
    }

    public function getReleases($repoName)
    {
        $path = "repos/$this->owner/$repoName/releases";

        return $this->executeCurlRequest($path);
    }

    public function getTags($repoName)
    {
        $path = "repos/$this->owner/$repoName/tags";

        return $this->executeCurlRequest($path);
    }

    public function getBranches($repoName)
    {
        $path = "repos/$this->owner/$repoName/branches";

        return $this->executeCurlRequest($path);
    }

    public function curlRefAndReleaseComparison($repoName, $releaseVersion, $branch)
    {
        $path = "repos/$this->owner/$repoName/compare/$releaseVersion...$branch";

        return $this->executeCurlRequest($path);
    }

    public function getSourceRefHead($repoName)
    {
        $path = "repos/$this->owner/$repoName/git/refs/heads/master";

        return $this->executeCurlRequest($path);
    }

    public function updateFile($repoName, $filename, $releaseData)
    {
        $path = "repos/$this->owner/$repoName/contents/$filename";

        return $this->executeCurlRequest($path, 'PUT', $releaseData);
    }

    public function releaseBranch($repoName, $releaseData)
    {
        $path = 'repos/' . $this->owner . '/' . $repoName . '/releases';

        $result = $this->executeCurlRequest($path, 'POST', $releaseData);
        if (isset($result->tag_name) && $result->tag_name === $releaseData['tag_name']) {
            $this->msg("Released $repoName $result->tag_name");

            return true;
        }

        var_dump($result);
        $this->err("Failed to release $repoName. Aborting");
    }

    public function createRef($repoName, $newRef, $sha)
    {
        $path     = "repos/$this->owner/$repoName/git/refs";
        $postData = [
            'ref' => 'refs/heads/' . $newRef,
            'sha' => $sha
        ];
        $result   = $this->executeCurlRequest($path, 'POST', $postData);

        if ($result === true || (isset($result->ref) && $result->ref === $postData['ref'])) {
            $this->msg("Branch $newRef created for $repoName");

            return true;
        }

        var_dump($result);
        $this->err("Failed to create new ref. Aborting");
    }

    public function getFile($repoName, $sourceRef, $filePath)
    {
        $data = ['ref' => $sourceRef];
        $path = "repos/$this->owner/$repoName/contents/$filePath";

        $response = $this->executeCurlRequest($path, 'GET', $data);

        if ($response->size <= 0 || $response->type !== 'file' || $response->encoding !== static::GITHUB_FILE_ENCODING) {
            $this->err("File is either empty or a diractory.");
        }

        return $response;
    }

    private function executeCurlRequest($urlPath, $requestType = 'GET', $requestData = [], $partialUrl = true, $partialBody = [])
    {
        $getParams = [
            'access_token' => $this->token,
            'per_page'     => 100
        ];
        if ($requestType === 'GET' && !empty($requestData)) {
            $getParams = $getParams + $requestData;
        }

        $url     = static::GITHUB_ROOT . $urlPath . '?' . http_build_query($getParams);
        $ch      = curl_init();
        $options = [
            CURLOPT_URL            => ($partialUrl) ? $url : $urlPath,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HEADER         => 1,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13'
        ];
        if (!in_array($requestType, ['GET', 'POST'])) {
            $options[CURLOPT_CUSTOMREQUEST] = $requestType;
        }
        if (in_array($requestType, ['POST', 'PUT']) && !empty($requestData)) {
            $options[CURLOPT_POSTFIELDS] = json_encode($requestData);
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers     = $this->parseHeaders(substr($response, 0, $header_size));
        $body        = @json_decode(substr($response, $header_size));

        if (isset($body->message)) {
            return $this->parseGithubApiResultMessage($body->message, $url, $headers);
        } elseif (is_array($body)) {
            $body = array_merge($partialBody, $body);
        } elseif (is_object($body)) {
            return $body;
        } else {
            var_dump($body);
            $this->err('Unable to parse github response');

        // really weird yet short recursive git API paginator
        if (array_key_exists('Link', $headers)) {
            $links      = [];
            $linksSplit = explode(', ', $headers['Link']);
            foreach ($linksSplit as $link) {
                $linkSplit                                  = explode('>; rel=', $link);
                $links[str_replace('"', '', $linkSplit[1])] = str_replace('<', '', $linkSplit[0]);
            }

            if (isset($links['next'])) {
                $body += $this->executeCurlRequest($links['next'], $requestType, $requestData, false, $body);
            }
        }

        return $body;
    }

    private function parseHeaders($headersString)
    {
        $headers = [];

        foreach (explode("\r\n", $headersString) as $i => $line)
            if ($i === 0) {
                $headers['http_code'] = $line;
            } elseif (strlen($line) === 0) {
                continue;
            } else {
                if (strpos($line, ': ') !== false) {
                    list ($key, $value) = explode(': ', $line);
                    $headers[$key] = $value;
                }
            }

        return $headers;
    }


    private function parseGithubApiResultMessage($message, $url, $curlOptions)
    {
        if (strpos($message, 'already exists') !== false) {
            return true;
        } elseif (strpos($message, 'composer.json does not match')) {
            var_dump($curlOptions);
            $this->err("Issues with composer.json sha. Deleting .X branch and retry");
        } else {
            var_dump($curlOptions);
            $this->msg("Failed to retrieve $url");
            $this->err("$message");
        }

        return false;
    }

    /**
     * @param string $message
     */
    private function msg($message = '')
    {
        echo "$message \n";
    }

    /**
     * @param string $message
     */
    private function err($message, $exitCode = 1)
    {
        echo "Error: $message \nABORTING!";
        exit($exitCode);
    }

    private function bye($message)
    {
        echo "Error: $message \nABORTING!";

        $e     = new \Exception();
        $trace = explode("\n", $e->getTraceAsString());
        // reverse array to make steps line up chronologically
        $trace = array_reverse($trace);
        array_shift($trace); // remove {main}
        array_pop($trace); // remove call to this method
        $length = count($trace);
        $result = [];

        for ($i = 0; $i < $length; $i++) {
            $result[] = ($i + 1) . ')' . substr(
                    $trace[$i],
                    strpos($trace[$i], ' ')
                ); // replace '#someNum' with '$i)', set the right ordering
        }

        $this->err("\t" . implode("\n\t", $result));
    }
}
