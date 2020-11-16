<?php

use Goutte\Client;
use Symfony\Component\HttpClient\HttpClient;

class SiteCrawler
{
    protected $inputFileName;
    protected $urls = [];
    protected $client;
    protected $logFileName = 'log.txt';
    protected $outputFile;
    protected $outputFileName = 'results.csv';
    protected $logFile;
    protected $depth;

    /**
     * SiteCrawler constructor.
     * @param string $inputFileName
     * @param int $depth
     */
    public function __construct(string $inputFileName = 'domains.txt', int $depth = 10)
    {
        $this->client = new Client(HttpClient::create(['timeout' => 10]));
        $this->depth = $depth;
        $this->inputFileName = $inputFileName;
        $this->logFile = fopen($this->logFileName, 'w');
        $this->outputFile = fopen($this->outputFileName, 'w');
        fputcsv($this->outputFile, ['url', 'emails', 'notes']);
    }

    /**
     * Start process crawling
     */
    public function startCrawl()
    {
        $this->getUrls();
        if (count($this->urls) > 0) {
            foreach ($this->urls as $index => $url) {
                if (!$this->parseSingleSite($url)) {
                    // If https doesn't respond try once again with http
                    $this->parseSingleSite(str_replace('https', 'http', $url));
                }
            }
        } else {
            echo "Empty input file!\n";
        }
    }

    protected function parseSingleSite($url)
    {
        echo "URL {$url} parsing started ...";
        $siteLinks = $this->getSiteLinks($url);
        if (is_array($siteLinks)) {
            if (count($siteLinks) > 0) {
                $emails = [];
                foreach ($siteLinks as $link) {
                    if ($foundEmails = $this->parseEmails($link)) {
                        $emails = array_merge($emails, $foundEmails);
                    }
                }
                $emails = array_unique($emails);
                $this->addResultRow($url, $emails);
                echo " FINISHED\n";
                return true;
            } else {
                echo " No links found\n";
                return true;
            }
        } else {
            echo " Site unreachable\n";
            return false;
        }
    }

    /**
     * Parse all internal links from the main page
     *
     * @param $url
     * @return array|false
     */
    protected function getSiteLinks($url)
    {
        try {
            $crawler = $this->client->request('GET', $url);
        } catch (Throwable $exception) {
            $this->addResultRow($url, '', "Ping Error - Code: " . $exception->getCode() . '. Message: ' . $exception->getMessage());
            $this->addLog("Ping main page error! URL:{$url}.  Code: " . $exception->getCode() . '. Message: ' . $exception->getMessage());
            return false;
        }
        // put main page to the list
        $links = [$url];
        $host = parse_url($url, PHP_URL_HOST);
        $crawler->filter('[href]')->each(function ($item) use (&$links, &$allLinks, $host) {
            try {
                $link = $item->link()->getUri();
            } catch (Throwable $exception) {
                return;
            }
            // Accept only valid url
            if ($parsedUrl = parse_url($link)) {
                // Refuse links with query strings and hashes
                if (isset($parsedUrl['query']) || isset($parsedUrl['fragment'])) {
                    return;
                }
                $hasExtension = isset($parsedUrl['path']) ? preg_match('/^.*\.(jpg|jpeg|png|gif|css|js|xml|json|bmp|mp3|pdf)/mi', $parsedUrl['path']) : false;
                // Refuse links to external resources and links to files
                if (isset($parsedUrl['host']) && strpos($parsedUrl['host'], $host) !== false && !$hasExtension) {
                    if (strpos($link, 'contact') !== false) {
                        // put link with 'contact' word to the top of list
                        array_unshift($links, $link);
                    } else {
                        $links[] = $link;
                    }
                }
            }
        });
        // Cut array based on depth option
        $links = array_slice(array_unique($links), 0, $this->depth);
        $this->addLog("Links from {$url} has been successfully parsed!");
        $this->addLog(print_r($links, true));

        return $links;
    }

    /**
     * Get urls from input file
     */
    protected function getUrls()
    {
        $inputFile = @fopen($this->inputFileName, 'r');
        if ($inputFile) {
            while (($domain = fgets($inputFile, 4096)) !== false) {
                $this->urls[] = $this->castUrl($domain);
            }
            if (!feof($inputFile)) {
                echo "Error occurred during reading a file\n";
                die();
            }
            fclose($inputFile);
        } else {
            echo "Input file not found\n";
            die();
        }
    }

    protected function parseEmails($link)
    {
        try {
            $crawler = $this->client->request('GET', $link);
        } catch (Throwable $exception) {
            $this->addLog("Get site link error! URL:{$link}.  Code: " . $exception->getCode() . '. Message: ' . $exception->getMessage());
            return false;
        }
        $parsedUrl = parse_url($link);
        $baseDomain = '';
        if (isset($parsedUrl['host'])) {
            $baseDomain = str_replace('www.', '', $parsedUrl['host']);
        }
        if ($crawler->count()) {
            $pageContent = strtolower($crawler->html());
            preg_match_all("/[a-z0-9_\-\+\.]+@{$baseDomain}?/i", $pageContent, $matchesCurrentDomain);

            return $matchesCurrentDomain[0];
        }

        return false;
    }

    protected function castUrl($url)
    {
        $url = str_replace('www.', '', $url);
        if (!parse_url($url, PHP_URL_SCHEME)) {
            $url = 'https://' . $url;
        }
        if ($path = parse_url($url, PHP_URL_PATH)) {
            $url = str_replace($path, '', $url);
        }

        return trim($url);
    }

    protected function addLog($text)
    {
        fwrite($this->logFile, "{$text}\n");
    }

    protected function addResultRow($url, $emails, $notes = '')
    {
        if (is_array($emails) && count($emails) > 0) {
            $emails = implode(':', $emails);
        } else {
            $emails = 'No emails found';
        }
        fputcsv($this->outputFile, [
            $url, $emails, $notes
        ]);
    }
}