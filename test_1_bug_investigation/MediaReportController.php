<?php
/**
 * MediaReportController
 * 
 * Handles generation of media campaign reports for clients
 * 
 * @author Original: John Kimani (2018)
 * @author Modified: Sarah Wanjiku (2020) - Added multi-campaign support
 * @author Modified: Peter Ochieng (2022) - PDF export functionality
 * 
 * NOTES: 
 * - Report generation can be slow for large date ranges
 * - Uses legacy report_data table structure
 * - Client reports are cached for 24 hours
 */

class MediaReportController extends Controller
{
    /**
     * Generates comprehensive media report for specified date range
     * 
     * @param int $campaignId Campaign ID
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     */
    public function actionGenerateReport($campaignId, $startDate, $endDate)
    {
        // Validate inputs
        if (!$this->validateDates($startDate, $endDate)) {
            throw new CHttpException(400, 'Invalid date range');
        }

        $campaign = Campaign::model()->findByPk($campaignId);
        if (!$campaign) {
            throw new CHttpException(404, 'Campaign not found');
        }

        // Check if we have a cached version
        $cacheKey = "report_{$campaignId}_{$startDate}_{$endDate}";
        $cached = Yii::app()->cache->get($cacheKey);

        if ($cached !== false) {
            $this->renderReport($cached);
            return;
        }

        // Generate fresh report
        $reportData = $this->collectReportData($campaignId, $startDate, $endDate);
        $processedData = $this->processReportData($reportData);
        $finalReport = $this->generateReportSummary($processedData, $campaign);

        // Cache for 24 hours
        Yii::app()->cache->set($cacheKey, $finalReport, 86400);

        $this->renderReport($finalReport);
    }

    /**
     * Collects raw report data from database
     * 
     * NOTE: This gets ALL impressions data - be careful with large date ranges!
     */
    protected function collectReportData($campaignId, $startDate, $endDate)
    {
        $data = array();

        // Get all impressions for this campaign in date range
        // Each impression record contains: timestamp, user_id, platform, location, etc.
        $impressions = Impression::model()->findAll(
            'campaign_id = :campaignId AND date >= :startDate AND date <= :endDate',
            array(
                ':campaignId' => $campaignId,
                ':startDate' => $startDate,
                ':endDate' => $endDate
            )
        );

        $data['total_impressions'] = count($impressions);
        $data['impressions'] = $impressions;

        // Get click data
        $clicks = Click::model()->findAll(
            'campaign_id = :campaignId AND date >= :startDate AND date <= :endDate',
            array(
                ':campaignId' => $campaignId,
                ':startDate' => $startDate,
                ':endDate' => $endDate
            )
        );

        $data['total_clicks'] = count($clicks);
        $data['clicks'] = $clicks;

        // Get conversion data
        $conversions = Conversion::model()->findAll(
            'campaign_id = :campaignId AND date >= :startDate AND date <= :endDate',
            array(
                ':campaignId' => $campaignId,
                ':startDate' => $startDate,
                ':endDate' => $endDate
            )
        );

        $data['total_conversions'] = count($conversions);
        $data['conversions'] = $conversions;

        return $data;
    }

    /**
     * Processes raw data into report metrics
     * 
     * This is where we calculate CTR, conversion rates, etc.
     */
    protected function processReportData($rawData)
    {
        $processed = array();

        // Calculate metrics
        $processed['total_impressions'] = $rawData['total_impressions'];
        $processed['total_clicks'] = $rawData['total_clicks'];
        $processed['total_conversions'] = $rawData['total_conversions'];

        // Calculate CTR
        if ($processed['total_impressions'] > 0) {
            $processed['ctr'] = ($processed['total_clicks'] / $processed['total_impressions']) * 100;
        } else {
            $processed['ctr'] = 0;
        }

        // Calculate conversion rate
        if ($processed['total_clicks'] > 0) {
            $processed['conversion_rate'] = ($processed['total_conversions'] / $processed['total_clicks']) * 100;
        } else {
            $processed['conversion_rate'] = 0;
        }

        // Break down by platform
        $platformBreakdown = array();
        foreach ($rawData['impressions'] as $impression) {
            $platform = $impression->platform;

            if (!isset($platformBreakdown[$platform])) {
                $platformBreakdown[$platform] = array(
                    'impressions' => 0,
                    'clicks' => 0,
                    'conversions' => 0
                );
            }

            $platformBreakdown[$platform]['impressions']++;
        }

        // Now count clicks per platform
        foreach ($rawData['clicks'] as $click) {
            $platform = $click->platform;
            if (isset($platformBreakdown[$platform])) {
                $platformBreakdown[$platform]['clicks']++;
            }
        }

        // Count conversions per platform
        foreach ($rawData['conversions'] as $conversion) {
            $platform = $conversion->platform;
            if (isset($platformBreakdown[$platform])) {
                $platformBreakdown[$platform]['conversions']++;
            }
        }

        $processed['platform_breakdown'] = $platformBreakdown;

        // Daily breakdown
        $dailyBreakdown = array();
        foreach ($rawData['impressions'] as $impression) {
            $date = date('Y-m-d', strtotime($impression->date));

            if (!isset($dailyBreakdown[$date])) {
                $dailyBreakdown[$date] = array(
                    'impressions' => 0,
                    'clicks' => 0,
                    'conversions' => 0
                );
            }

            $dailyBreakdown[$date]['impressions']++;
        }

        foreach ($rawData['clicks'] as $click) {
            $date = date('Y-m-d', strtotime($click->date));
            if (isset($dailyBreakdown[$date])) {
                $dailyBreakdown[$date]['clicks']++;
            }
        }

        foreach ($rawData['conversions'] as $conversion) {
            $date = date('Y-m-d', strtotime($conversion->date));
            if (isset($dailyBreakdown[$date])) {
                $dailyBreakdown[$date]['conversions']++;
            }
        }

        $processed['daily_breakdown'] = $dailyBreakdown;

        // Geographic breakdown
        $geoBreakdown = array();

        // Instead of looking up every user's location one after the other, get IDs first then fetch locations in one query
        // foreach ($rawData['impressions'] as $impression) {
        //     // Each impression has user_id - we need to look up their location
        //     $user = User::model()->findByPk($impression->user_id);
        //     if ($user) {
        //         $country = $user->country;

        //         if (!isset($geoBreakdown[$country])) {
        //             $geoBreakdown[$country] = array(
        //                 'impressions' => 0,
        //                 'clicks' => 0
        //             );
        //         }

        //         $geoBreakdown[$country]['impressions']++;
        //     }
        // }

        // Collect IDs first from impressions
        $userIds = array();

        foreach ($rawData['impressions'] as $impression) {
            $userIds[] = $impression->user_id;
        }

        $userIds = array_unique($userIds);

        // Fetch all users in one query using IN clause
        $users = array();
        if (count($userIds) > 0) {
            $userRecords = User::model()->findAll(array(
                'condition' => 'id IN (' . implode(',', array_map('intval', $userIds)) . ')'
            ));

            // Index users by ID for quick lookup in the new userData associative array
            foreach ($userRecords as $user) {
                $users[$user->id] = $user;
            }
        }

        // Use the indexed users to build geo breakdown for impressions
        foreach ($rawData['impressions'] as $impression) {
            if (isset($users[$impression->user_id])) {
                $country = $users[$impression->user_id]->country;

                if (!isset($geoBreakdown[$country])) {
                    $geoBreakdown[$country] = array(
                        'impressions' => 0,
                        'clicks' => 0
                    );
                }

                $geoBreakdown[$country]['impressions']++;
            }
        }

        // Apply the same for clicks
        // foreach ($rawData['clicks'] as $click) {
        //     $user = User::model()->findByPk($click->user_id);
        //     if ($user && isset($geoBreakdown[$user->country])) {
        //         $geoBreakdown[$user->country]['clicks']++;
        //     }
        // }

        // Since we already have the users indexed, we can directly use them
        foreach ($rawData['clicks'] as $click) {
            if (isset($rawData->user_id)) {
                $country = $users[$click->user_id]->country;

                if (isset($geoBreakdown[$country])) {
                    $geoBreakdown[$country]['clicks']++;
                }

            }
        }

        $processed['geo_breakdown'] = $geoBreakdown;

        return $processed;
    }

    /**
     * Generates final report with campaign details
     */
    protected function generateReportSummary($processedData, $campaign)
    {
        $summary = array(
            'campaign_name' => $campaign->name,
            'campaign_id' => $campaign->id,
            'budget' => $campaign->budget,
            'metrics' => $processedData,
            'generated_at' => date('Y-m-d H:i:s')
        );

        return $summary;
    }

    /**
     * Renders report as JSON or PDF
     */
    protected function renderReport($reportData)
    {
        if (isset($_GET['format']) && $_GET['format'] == 'pdf') {
            $this->renderPDF($reportData);
        } else {
            header('Content-Type: application/json');
            echo json_encode($reportData);
        }
    }

    /**
     * Validates date inputs
     */
    protected function validateDates($startDate, $endDate)
    {
        $start = strtotime($startDate);
        $end = strtotime($endDate);

        if ($start === false || $end === false) {
            return false;
        }

        if ($start > $end) {
            return false;
        }

        return true;
    }

    /**
     * Renders report as PDF
     * Added by Peter O. - 2022
     */
    protected function renderPDF($reportData)
    {
        // PDF generation logic here
        // Uses TCPDF library

        Yii::import('application.vendors.tcpdf.*');
        require_once('tcpdf.php');

        $pdf = new TCPDF();
        $pdf->AddPage();

        $html = $this->renderPartial('_report_template', array('data' => $reportData), true);
        $pdf->writeHTML($html, true, false, true, false, '');

        $pdf->Output('report.pdf', 'D');
    }
}
