<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\ReportModel;

class ReportController extends BaseController
{
   public function reportUser(){

    $isToken = check_jwt_authentication();

    if($isToken){

        try{

            $reportedId = $this->request->getVar('reported_id');
            $report = $this->request->getVar('report');

            $reportModel = new ReportModel();

            $data = [
                'r_reporting_user' => $isToken->user_id,
                'r_reported_user' => $reportedId,
                'r_report_msg' => $report,
            ];

            if($reportModel->save($data)){

                return $this->response->setJSON([
                    'status' => true,
                    'message' => 'User Reported Successfully.'
                ]);

            } else{

                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'Failed to report user.'
                ]);
            }

        } catch(\Throwable $e){

            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    } else{
        return $this->response->setJSON([
            'status' => false,
            'message' => 'Authorization Failed',
        ]);
    }
   }
}
