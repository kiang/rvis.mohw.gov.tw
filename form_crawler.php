<?php
class FormRvisCrawler {
    private $baseUrl = 'https://rvis.mohw.gov.tw/mgov-rvis/home/map';
    private $csvFile = 'rvis_all_data.csv';
    private $csrfToken = '';
    
    public function __construct() {
        $this->initCsv();
    }
    
    private function initCsv() {
        $headers = ['county', 'name', 'phone', 'address', 'lat', 'lng'];
        $file = fopen($this->csvFile, 'w');
        fputcsv($file, $headers);
        fclose($file);
    }
    
    public function crawl() {
        echo "Starting form-based RVIS crawler...\n";
        
        // First get the main page to extract CSRF token
        $mainHtml = $this->makeRequest($this->baseUrl);
        if (!$mainHtml) {
            echo "Failed to fetch main page\n";
            return;
        }
        
        // Extract CSRF token
        if (preg_match('/name="_csrf" value="([^"]+)"/', $mainHtml, $matches)) {
            $this->csrfToken = $matches[1];
            echo "Found CSRF token: " . substr($this->csrfToken, 0, 10) . "...\n";
        } else {
            echo "Could not find CSRF token\n";
            return;
        }
        
        // Start crawling with pagination
        $page = 1;
        $totalLocations = 0;
        
        do {
            echo "Processing page {$page}...\n";
            
            $pageHtml = $this->submitForm($page);
            if (!$pageHtml) {
                echo "Failed to fetch page {$page}\n";
                break;
            }
            
            // Extract locations from JavaScript and table data from HTML
            $jsLocations = $this->extractLocationsFromJs($pageHtml);
            $tableData = $this->extractTableData($pageHtml);
            
            // Merge JS locations with table data
            $locations = $this->mergeLocationData($jsLocations, $tableData);
            $locationCount = count($locations);
            
            if ($locationCount > 0) {
                echo "Found {$locationCount} locations on page {$page}\n";
                $this->saveLocationsToCsv($locations, $page);
                $totalLocations += $locationCount;
                $page++;
                
                // Add delay between requests
                sleep(1);
            } else {
                echo "No locations found on page {$page}, stopping\n";
                break;
            }
            
            
        } while ($locationCount > 0);
        
        echo "Crawling completed. Total locations: {$totalLocations}\n";
        echo "Data saved to {$this->csvFile}\n";
    }
    
    private function submitForm($page = 1) {
        $postData = [
            '_csrf' => $this->csrfToken,
            'p' => $page,
            'countySel' => '',
            'townSel' => '',
            'villageSel' => ''
        ];
        
        return $this->makeRequest($this->baseUrl, $postData);
    }
    
    private function extractLocationsFromJs($html) {
        $locations = [];
        
        // Look for locations array in JavaScript
        if (preg_match('/let locations = (\[.*?\]);/s', $html, $matches)) {
            $locationsJs = $matches[1];
            
            // Clean up JavaScript to make it valid JSON
            $locationsJs = preg_replace('/(\w+):\s*/', '"$1": ', $locationsJs);
            $locationsJs = preg_replace('/,\s*}/', '}', $locationsJs);
            $locationsJs = preg_replace('/,\s*]/', ']', $locationsJs);
            $locationsJs = str_replace("'", '"', $locationsJs);
            
            $decoded = json_decode($locationsJs, true);
            if ($decoded && is_array($decoded)) {
                // Ensure we have the label field for matching
                foreach ($decoded as &$location) {
                    if (!isset($location['label']) && isset($location['name'])) {
                        $location['label'] = $location['name'];
                    }
                }
                return $decoded;
            }
        }
        
        // Alternative parsing if JSON decode fails
        return $this->parseLocationsManually($html);
    }
    
    private function parseLocationsManually($html) {
        $locations = [];
        
        // Look for location objects in the JavaScript
        if (preg_match('/let locations = \[(.*?)\];/s', $html, $matches)) {
            $locationsString = $matches[1];
            
            // Extract each location object
            if (preg_match_all('/\{([^}]+)\}/', $locationsString, $objectMatches)) {
                foreach ($objectMatches[1] as $objectContent) {
                    $location = [];
                    
                    // Extract coordinates
                    if (preg_match('/lat:\s*([0-9.-]+)/', $objectContent, $match)) {
                        $location['lat'] = floatval($match[1]);
                    }
                    if (preg_match('/lng:\s*([0-9.-]+)/', $objectContent, $match)) {
                        $location['lng'] = floatval($match[1]);
                    }
                    
                    // Extract label field specifically for matching
                    if (preg_match('/label:\s*["\']([^"\']*)["\']/', $objectContent, $match)) {
                        $location['label'] = trim($match[1]);
                    }
                    
                    if (isset($location['lat']) && isset($location['lng'])) {
                        $locations[] = $location;
                    }
                }
            }
        }
        
        return $locations;
    }
    
    private function extractTableData($html) {
        $tableData = [];
        
        // Look for the data table in the HTML
        if (preg_match('/<table[^>]*class="[^"]*table[^"]*"[^>]*>(.*?)<\/table>/s', $html, $tableMatch)) {
            $tableHtml = $tableMatch[1];
            
            // Extract table rows
            if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $tableHtml, $rowMatches)) {
                $isFirstRow = true;
                foreach ($rowMatches[1] as $rowHtml) {
                    // Skip header row
                    if ($isFirstRow && preg_match('/<th[^>]*>/i', $rowHtml)) {
                        $isFirstRow = false;
                        continue;
                    }
                    $isFirstRow = false;
                    
                    // Extract table cells
                    if (preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $rowHtml, $cellMatches)) {
                        $cells = $cellMatches[1];
                        
                        if (count($cells) >= 4) {
                            $rowData = [
                                'county' => trim(strip_tags($cells[0] ?? '')),
                                'name' => trim(strip_tags($cells[1] ?? '')),
                                'phone' => trim(strip_tags($cells[2] ?? '')),
                                'address' => trim(strip_tags($cells[3] ?? ''))
                            ];
                            
                            $tableData[] = $rowData;
                        }
                    }
                }
            }
        }
        
        return $tableData;
    }
    
    private function mergeLocationData($jsLocations, $tableData) {
        $mergedData = [];
        
        // Try to match by label/name first, otherwise fall back to order matching
        foreach ($tableData as $tableRow) {
            $location = $tableRow;
            $matched = false;
            
            // Try to find matching JS location by label
            foreach ($jsLocations as $jsLocation) {
                if (isset($jsLocation['label']) && isset($tableRow['name'])) {
                    // Clean both strings for comparison
                    $jsLabel = trim($jsLocation['label']);
                    $tableName = trim($tableRow['name']);
                    
                    if ($jsLabel === $tableName) {
                        $location['lat'] = $jsLocation['lat'] ?? '';
                        $location['lng'] = $jsLocation['lng'] ?? '';
                        $matched = true;
                        break;
                    }
                }
            }
            
            // If no match found by name, try order-based matching
            if (!$matched) {
                $index = count($mergedData);
                if (isset($jsLocations[$index])) {
                    $location['lat'] = $jsLocations[$index]['lat'] ?? '';
                    $location['lng'] = $jsLocations[$index]['lng'] ?? '';
                }
            }
            
            $mergedData[] = $location;
        }
        
        // Add any remaining JS locations that didn't match table data
        if (count($jsLocations) > count($tableData)) {
            for ($i = count($tableData); $i < count($jsLocations); $i++) {
                if (isset($jsLocations[$i])) {
                    $location = [
                        'county' => '',
                        'name' => $jsLocations[$i]['label'] ?? '',
                        'phone' => '',
                        'address' => '',
                        'lat' => $jsLocations[$i]['lat'] ?? '',
                        'lng' => $jsLocations[$i]['lng'] ?? ''
                    ];
                    $mergedData[] = $location;
                }
            }
        }
        
        return $mergedData;
    }
    
    private function saveLocationsToCsv($locations, $page) {
        $file = fopen($this->csvFile, 'a');
        
        foreach ($locations as $location) {
            $row = [
                $location['county'] ?? '',
                $location['name'] ?? '',
                $location['phone'] ?? '',
                $location['address'] ?? '',
                $location['lat'] ?? '',
                $location['lng'] ?? ''
            ];
            
            fputcsv($file, $row);
        }
        
        fclose($file);
    }
    
    private function makeRequest($url, $postData = null) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIEJAR => 'cookies.txt',
            CURLOPT_COOKIEFILE => 'cookies.txt',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: same-origin'
            ]
        ]);
        
        if ($postData) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive',
                'Content-Type: application/x-www-form-urlencoded',
                'Referer: ' . $this->baseUrl,
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: same-origin'
            ]);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_error($ch)) {
            echo "CURL Error: " . curl_error($ch) . "\n";
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        
        if ($httpCode !== 200) {
            echo "HTTP Error: {$httpCode}\n";
            return false;
        }
        
        return $response;
    }
}

// Run the crawler
if (php_sapi_name() === 'cli') {
    $crawler = new FormRvisCrawler();
    $crawler->crawl();
} else {
    echo "This script should be run from command line.\n";
}
?>