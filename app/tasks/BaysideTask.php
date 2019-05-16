<?php

use Aiden\Models\Das;

class BaysideTask extends _BaseTask
{

    public $council_name = "Bayside";
    public $council_website_url = "https://eplanning.bayside.nsw.gov.au";
    public $council_params = [];
    public $council_default_param = "";

    public function scrapeAction($params = [])
    {

        $url = "https://eplanning.bayside.nsw.gov.au/ePlanning/Pages/XC.Track/SearchApplication.aspx?d=thismonth&k=LodgementDate&t=217";
        $this->logger->info($url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config->useragent);

        $output = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            $this->logger->error("cURL error: {errmsg} ({errno})", ["errmsg" => $errmsg, "errno" => $errno]);
            return false;
        }

        $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
        if (!$html) {
            $this->logger->error("Could not parse HTML");
            return false;
        }

        $daElements = $html->find(".result");
        foreach ($daElements as $daElement) {
            // Get the council reference so we can check if the DA is known
            $daCouncilReferenceElement = $daElement->children(0);
            if ($daCouncilReferenceElement === null) {
                continue;
            }

            $daCouncilReference = $this->cleanString($daCouncilReferenceElement->innertext());
            $councilUrl = 'https://eplanning.bayside.nsw.gov.au/ePlanning/'.str_replace('../../', '', $daCouncilReferenceElement->getAttribute('href'));
            $this->logger->info('DA: ' . $daCouncilReference);
            $this->logger->info('Council URL: ' . $councilUrl);

            $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
            $da->setCouncilId($this->getCouncil()->getId());
            $da->setCouncilReference($daCouncilReference);
            $da->setCouncilUrl($councilUrl);

            if ($da->save()) {

                $this->logger->info("");
                $this->logger->info("Created new development application {da_id} ({da_reference})", [
                    "da_id" => $da->getId(),
                    "da_reference" => $da->getCouncilReference()
                ]);

                $daHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($daElement->outertext());
                if ($daHtml === false) {
                    continue;
                }

                // All information is available in the XML, so no need to have a separate scrapeMetaAction()-method
                $this->scrapeMeta($daHtml, $da);
            } else {
                $this->logger->info("Could not save development application ({error})", ["error" => print_r($da->getMessages(), true)]);
            }

        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $this->logger->info("Done.");

    }

    public function scrapeMetaAction()
    {

        $this->logger->info("This council does not offer DA-specific pages, so all of the "
            . "information for a development application is pulled from the initial scrape()-method.");
        return false;

    }

    protected function extractAddresses($html, $da, $params = null): bool
    {

        $addedAddresses = 0;

        $addressElement = $html->find("strong");
        if ($addressElement === null) {
            $this->logger->info("ELement not found");
            return false;
        }

        foreach ($addressElement as $add) {
            $address = $this->cleanString($add->innertext());
            $this->logger->info("Address " . $addedAddresses . ": " . $address);

            if ($address) {
                if ($this->saveAddress($da, $address) === true) {
                    $addedAddresses++;
                }
            }
        }


        return ($addedAddresses > 0);

    }

    protected function extractApplicants($html, $da, $params = null): bool
    {
        $removeThis = '<!--../../Pages/XC.Track.East.Party/SearchParty.aspx?id={PartyId}-->';
        $addedApplicant = 0;
        $url = $da->getCouncilUrl();
        $html = $this->scrapeTo($url);
        $detail = $html->find('.detail', 2);
        $detailright = $detail->find('.detailright', 0);
        $containerText = $this->cleanString($detailright->innertext());
        $peopleParts = explode('<br />', $containerText);

        for($x = 0; $x < count($peopleParts); $x++){
            if($peopleParts[$x] != ''){
                $containerParts = explode('-', str_replace($removeThis, '', $peopleParts[$x]));
                $role = trim($containerParts[0]);

                if(strpos($role, 'span') === false){
                    if(strtolower($role) != 'officer'){
                        $applicant = trim($containerParts[1]);
                        if ($this->saveParty($da, $role, $applicant)) {
                            $addedApplicant++;
                        }
                    }
                }

            }
        }

        return ($addedApplicant > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool
    {

        $url = $da->getCouncilUrl();
        $html = $this->scrapeTo($url);
        $detail = $html->find('.detail', 0);
        $detailright = $detail->find('.detailright', 0);
        $container = $detailright->find('div', 0);
        if($container == null){
          return false;
        }
        $value = $this->cleanString($container->innertext());
        return $this->saveDescription($da, $value);

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool
    {
        $url = $da->getCouncilUrl();
        $html = $this->scrapeTo($url);
        $detail = $html->find('.detail', 0);
        $detailright = $detail->find('.detailright', 0);
        $estimatedCostContainer = $detailright->find('div', 3);
        $value = $this->cleanString($estimatedCostContainer->innertext());
        return $this->saveEstimatedCost($da, $value);

    }

    protected function extractLodgeDate($html, $da, $params = null): bool
    {
        $url = $da->getCouncilUrl();
        $html = $this->scrapeTo($url);
        $detail = $html->find('.detail', 0);
        $detailright = $detail->find('.detailright', 0);
        $lodgeDateContiner = $detailright->find('div', 2);

        $value = $this->cleanString($lodgeDateContiner->innertext());
        $dateParts = str_replace("Lodged: ", "", $value);
        $this->logger->info("Date Parts: " .$dateParts);
        $date = \DateTime::createFromFormat("d/m/Y", $dateParts);
        return $this->saveLodgeDate($da, $date);

    }

    protected function extractOfficers($html, $da, $params = null): bool
    {
        $addedOfficers = 0;
        $url = $da->getCouncilUrl();
        $html = $this->scrapeTo($url);
        $detail = $html->find('.detail', 0);
        $detailright = $detail->find('.detailright', 0);
        $container = $detailright->find('div', 4);
        $containerText = $this->cleanString($container->innertext());
        $containerParts = explode(':', $containerText);
        $role = $containerParts[0];
        if(strtolower($role) == 'officer'){
            $officer = trim($containerParts[1]);
            if ($this->saveParty($da, $role, $officer)) {
                $addedOfficers++;
            }
        }

        return ($addedOfficers > 0);
    }

    protected function extractPeople($html, $da, $params = null): bool
    {
        return false;

    }

    protected function extractDocuments($html, $da, $params = null): bool
    {
        $addedDocuments = 0;
        $url = $da->getCouncilUrl();
        $html = $this->scrapeTo($url);
        $filesContainer = $html->find('.file');
        foreach($filesContainer as $file){
          $a = $file->find('a', 1);
          if($a){
              $documentName = $this->cleanString($a->innertext());
              $documentUrl = 'https://eplanning.bayside.nsw.gov.au/ePlanning/'.str_replace('../../', '', $a->getAttribute('href'));

              if ($this->saveDocument($da, $documentName, $documentUrl)) {
                  $addedDocuments++;
              }
          }
        }

        return ($addedDocuments > 0);

    }

    function scrapeTo($url){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config->useragent);

        $output = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            $this->logger->error("cURL error: {errmsg} ({errno})", ["errmsg" => $errmsg, "errno" => $errno]);
            return false;
        }

        return \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
    }

}
