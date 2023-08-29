<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{
    /** @var BookingRepository */
    protected $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * Retrieve jobs based on user or admin/superadmin roles.
     * 
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        if ($user_id = $request->get('user_id')) {
            // Get jobs for specific user
            $response = $this->repository->getUsersJobs($user_id);
        } elseif (in_array($request->__authenticatedUser->user_type, [env('ADMIN_ROLE_ID'), env('SUPERADMIN_ROLE_ID')])) {
            // Get all jobs for admin/superadmin
            $response = $this->repository->getAll($request);
        }

        return response($response);
    }

    /**
     * Show details of a specific job.
     * 
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        // Get job details along with related translator and user
        $job = $this->repository->with('translatorJobRel.user')->find($id);

        return response($job);
    }

    /**
     * Store a new job.
     * 
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request)
    {
        $data = $request->all();

        // Store the job using authenticated user and data
        $response = $this->repository->store($request->__authenticatedUser, $data);

        return response($response);
    }

    /**
     * Update a job by id.
     * 
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request)
    {
        $data = $request->all();
        $cuser = $request->__authenticatedUser;
		
		// Update the job using request data
        $response = $this->repository->updateJob($id, array_except($data, ['_token', 'submit']), $cuser);

        return response($response);
    }

    /**
     * Store job and send email immediately.
     * 
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request)
    {
        $adminSenderEmail = config('app.adminemail');
        $data = $request->all();

		// Store and send job creation email
        $response = $this->repository->storeJobEmail($data);

        return response($response);
    }

    /**
     * Get job history by user id.
     * 
     * @param Request $request
     * @return null
     */
    public function getHistory(Request $request)
    {
        if($user_id = $request->get('user_id')) {

			// Get job history of specific user
            $response = $this->repository->getUsersJobsHistory($user_id, $request);
            return response($response);
        }

        return null;
    }

    /**
     * Accept a job.
	 *
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

		// Accept job for a user
        $response = $this->repository->acceptJob($data, $user);

        return response($response);
    }

    /**
     * Accept job by id.
	 *
     * @param Request $request
     * @return mixed
     */
    public function acceptJobWithId(Request $request)
    {
        $data = $request->get('job_id');
        $user = $request->__authenticatedUser;

		// Accept job for an authenticated user by job id
        $response = $this->repository->acceptJobWithId($data, $user);

        return response($response);
    }

    /**
     * Cancel job for a user.
	 *
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

		// Cancel job for a user asynchronous
        $response = $this->repository->cancelJobAjax($data, $user);

        return response($response);
    }

    /**
     * End job.
	 *
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $data = $request->all();

		// End the specific job
        $response = $this->repository->endJob($data);

        return response($response);

    }

    /**
     * Deny application.
	 *
     * @param Request $request
     * @return mixed
     */
    public function customerNotCall(Request $request)
    {
        $data = $request->all();

		// Deny customer application
        $response = $this->repository->customerNotCall($data);

        return response($response);

    }

    /**
     * Get potensial jobs.
	 *
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

		// Get potensial jobs for authenticated user
        $response = $this->repository->getPotentialJobs($user);

        return response($response);
    }

	/**
     * Update distance and session information of a job.
     * 
     * @param Request $request
     * @return mixed
     */
    public function distanceFeed(Request $request)
    {
        $data = $request->all();

        $distance = $data['distance'] ?? '';
        $time = $data['time'] ?? '';
        $jobid = $data['jobid'] ?? '';
        $session = $data['session_time'] ?? '';
        $flagged = $data['flagged'] === 'true' && $data['admincomment'] ? 'yes' : 'no';
        $manually_handled = $data['manually_handled'] === 'true' ? 'yes' : 'no';
        $by_admin = $data['by_admin'] === 'true' ? 'yes' : 'no';
        $admincomment = $data['admincomment'] ?? '';

        // Update distance and time if provided
        if ($time || $distance) {
            Distance::where('job_id', '=', $jobid)->update(['distance' => $distance, 'time' => $time]);
        }

        // Update admin-related details if provided
        if ($admincomment || $session || $flagged || $manually_handled || $by_admin) {
            Job::where('id', '=', $jobid)->update([
                'admin_comments' => $admincomment,
                'flagged' => $flagged,
                'session_time' => $session,
                'manually_handled' => $manually_handled,
                'by_admin' => $by_admin,
            ]);
        }

        return response('Record updated!');
    }

	/**
     * Reopen a job.
     * 
     * @param Request $request
     * @return mixed
     */
    public function reopen(Request $request)
    {
        $data = $request->all();
		
		// Reopen a specific job by data
        $response = $this->repository->reopen($data);

        return response($response);
    }

	/**
     * Resend notification.
     * 
     * @param Request $request
     * @return mixed
     */
    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);
		
		// Resend the notification of specific job to translator
        $this->repository->sendNotificationTranslator($job, $job_data, '*');

        return response(['success' => 'Push sent']);
    }

    /**
     * Sends SMS to Translator
	 *
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);

        try {
			
			// Send the notification SMS to translator
            $this->repository->sendSMSNotificationToTranslator($job);
            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }
    }

}
