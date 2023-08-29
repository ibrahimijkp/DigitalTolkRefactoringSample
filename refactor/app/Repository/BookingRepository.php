<?php

namespace DTApi\Repository;

use Event;
use Carbon\Carbon;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Mailers\MailerInterface;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{
    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * Constructor
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->initializeLogger();
    }

    /**
     * Initialize the logger
     */
    private function initializeLogger()
    {
        $this->logger = new Logger('admin_logger');
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
	 * Retrieve jobs for the user.
	 *
	 * @param int $user_id The ID of the user.
	 * @return array An array containing categorized jobs and user information.
	 */
	public function getUsersJobs($user_id)
	{
		// Find the user based on the given user ID
		$cuser = User::find($user_id);

		// Initialize variables for categorizing jobs
		$emergencyJobs = [];
		$normalJobs = [];
		$usertype = '';

		// Check user type and retrieve relevant jobs
		if ($cuser && $cuser->is('customer')) {
			// Retrieve pending, assigned, and started jobs for the customer
			$jobs = $cuser->jobs()
				->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
				->whereIn('status', ['pending', 'assigned', 'started'])
				->orderBy('due', 'asc')
				->get();
			$usertype = 'customer';
		} elseif ($cuser && $cuser->is('translator')) {
			// Retrieve new jobs for the translator
			$jobs = Job::getTranslatorJobs($cuser->id, 'new');
			$jobs = $jobs->pluck('jobs')->all();
			$usertype = 'translator';
		}

		// Categorize jobs into emergency and normal jobs
		foreach ($jobs as $jobitem) {
			if ($jobitem->immediate == 'yes') {
				$emergencyJobs[] = $jobitem;
			} else {
				$normalJobs[] = $jobitem;
			}
		}

		// Add user check information to normal jobs
		$normalJobs = collect($normalJobs)->each(function ($item) use ($user_id) {
			$item['usercheck'] = Job::checkParticularJob($user_id, $item);
		})->sortBy('due')->all();

		// Return categorized jobs and user information
		return [
			'emergencyJobs' => $emergencyJobs,
			'normalJobs' => $normalJobs,
			'cuser' => $cuser,
			'usertype' => $usertype,
		];
	}

    /**
	 * Retrieve historical jobs for the user.
	 *
	 * @param int     $user_id  The ID of the user.
	 * @param Request $request  The HTTP request instance.
	 * @return array An array containing categorized historical jobs, user information, and pagination data.
	 */
	public function getUsersJobsHistory($user_id, Request $request)
	{
		// Get the current page number from the request
		$page = $request->get('page');
		$pagenum = isset($page) ? $page : "1";

		// Find the user based on the given user ID
		$cuser = User::find($user_id);

		// Initialize variables for categorizing jobs and user type
		$emergencyJobs = [];
		$normalJobs = [];
		$usertype = '';

		// Check user type and retrieve relevant historical jobs
		if ($cuser && $cuser->is('customer')) {
			// Retrieve completed, withdrawn, and timed out jobs for the customer
			$jobs = $cuser->jobs()
				->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
				->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
				->orderBy('due', 'desc')
				->paginate(15);
			$usertype = 'customer';
			return [
				'emergencyJobs' => $emergencyJobs,
				'normalJobs' => [],
				'jobs' => $jobs,
				'cuser' => $cuser,
				'usertype' => $usertype,
				'numpages' => 0,
				'pagenum' => 0,
			];
		} elseif ($cuser && $cuser->is('translator')) {
			// Retrieve historical jobs for the translator
			$jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
			$totaljobs = $jobs_ids->total();
			$numpages = ceil($totaljobs / 15);

			$usertype = 'translator';

			$jobs = $jobs_ids;
			$normalJobs = $jobs_ids;

			// Return categorized historical jobs, user information, and pagination data
			return [
				'emergencyJobs' => $emergencyJobs,
				'normalJobs' => $normalJobs,
				'jobs' => $jobs,
				'cuser' => $cuser,
				'usertype' => $usertype,
				'numpages' => $numpages,
				'pagenum' => $pagenum,
			];
		}
	}


    /**
	 * Store a new job booking.
	 *
	 * @param StoreUserRequest $data The custom request validating the user data.
	 * @param User $user The user creating the booking.
	 * @return array The response indicating the status of the booking creation.
	 */
	public function store(StoreUserRequest $data,User $user)
	{
		$immediatetime = 5;
		$consumer_type = $user->userMeta->consumer_type;

		// Check if the user is a customer
		if ($user->user_type == env('CUSTOMER_ROLE_ID')) {
			$cuser = $user;

			// Process immediate booking or regular booking
			if ($data['immediate'] == 'yes') {
				$due_carbon = Carbon::now()->addMinute($immediatetime);
				$data['due'] = $due_carbon->format('Y-m-d H:i:s');
				$data['immediate'] = 'yes';
				$data['customer_phone_type'] = 'yes';
				$response['type'] = 'immediate';
			} else {
				$due = $data['due_date'] . " " . $data['due_time'];
				$response['type'] = 'regular';
				$due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
				$data['due'] = $due_carbon->format('Y-m-d H:i:s');
				if ($due_carbon->isPast()) {
					return ['status' => 'fail', 'message' => "Can't create booking in the past"];
				}
			}

			// Set customer phone type
			$data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';

			// Set customer physical type
			$data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';
			$response['customer_physical_type'] = $data['customer_physical_type'];

			// Set gender and certified options
			$data['gender'] = $this->getGenderOption($data['job_for']);
			$data['certified'] = $this->getCertifiedOption($data['job_for']);

			// Set job type based on consumer type
			$data['job_type'] = $this->getJobType($consumer_type);

			// Set timestamps and admin flag
			$data['b_created_at'] = now();
			if (isset($due)) {
				$data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
			}
			$data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

			// Create the job
			$job = $cuser->jobs()->create($data);

			// Build response
			$response['status'] = 'success';
			$response['id'] = $job->id;
			$data['job_for'] = $this->getFormattedJobForOptions($job);

			$data['customer_town'] = $cuser->userMeta->city;
			$data['customer_type'] = $cuser->userMeta->customer_type;

			return $response;
		} else {
			return ['status' => 'fail', 'message' => "Translator cannot create booking"];
		}
	}

	/**
	 * Get the gender option based on selected job options.
	 *
	 * @param array $jobForOptions The selected job options.
	 * @return string|null The gender option or null if not applicable.
	 */
	private function getGenderOption(array $jobForOptions)
	{
		if (in_array('male', $jobForOptions)) {
			return 'male';
		} elseif (in_array('female', $jobForOptions)) {
			return 'female';
		}
		return null;
	}

	/**
	 * Get the certified option based on selected job options.
	 *
	 * @param array $jobForOptions The selected job options.
	 * @return string|null The certified option or null if not applicable.
	 */
	private function getCertifiedOption(array $jobForOptions)
	{
		if (in_array('normal', $jobForOptions)) {
			return 'normal';
		} elseif (in_array('certified', $jobForOptions)) {
			return 'yes';
		} elseif (in_array('certified_in_law', $jobForOptions)) {
			return 'law';
		} elseif (in_array('certified_in_helth', $jobForOptions)) {
			return 'health';
		}
		if (in_array('normal', $jobForOptions) && in_array('certified', $jobForOptions)) {
			return 'both';
		} elseif (in_array('normal', $jobForOptions) && in_array('certified_in_law', $jobForOptions)) {
			return 'n_law';
		} elseif (in_array('normal', $jobForOptions) && in_array('certified_in_helth', $jobForOptions)) {
			return 'n_health';
		}
		return null;
	}

	/**
	 * Get the job type based on consumer type.
	 *
	 * @param string $consumerType The consumer type.
	 * @return string The job type.
	 */
	private function getJobType($consumerType)
	{
		if ($consumer_type == 'rwsconsumer')
			return 'rws';
		else if ($consumer_type == 'ngo')
			return 'unpaid';
		else if ($consumer_type == 'paid')
			return 'paid';
		return '';
	}

	/**
	 * Get the formatted job_for options.
	 *
	 * @param Job $job The job instance.
	 * @return array The formatted job_for options.
	 */
	private function getFormattedJobForOptions(Job $job)
	{
		$formattedOptions = [];

		if ($job->gender != null) {
			if ($job->gender == 'male') {
				$formattedOptions[] = 'Man';
			} elseif ($job->gender == 'female') {
				$formattedOptions[] = 'Kvinna';
			}
		}

		if ($job->certified != null) {
			if ($job->certified == 'both') {
				$formattedOptions[] = 'normal';
				$formattedOptions[] = 'certified';
			} else if ($job->certified == 'yes') {
                    $formattedOptions[] = 'certified';
			} else {
				$formattedOptions[] = $job->certified;
			}
		}

		return $formattedOptions;
	}

    /**
	 * Store job details and send email notification.
	 *
	 * @param array $data The data containing job and user details.
	 * @return array The response indicating the status of the operation.
	 */
	public function storeJobEmail(array $data)
	{
		// Extract data
		$userType = $data['user_type'];
		$userEmailJobId = @$data['user_email_job_id'];

		// Find the job by ID
		$job = Job::findOrFail($userEmailJobId);

		// Update job details
		$this->updateJobDetails($job, $data);

		// Send email notification
		$this->sendJobEmailNotification($job);

		// Build response
		$response = [
			'type' => $userType,
			'job' => $job,
			'status' => 'success'
		];

		// Convert job to data and fire event
		$jobData = $this->jobToData($job);
		Event::fire(new JobWasCreated($job, $jobData, '*'));

		return $response;
	}

	/**
	 * Update job details based on provided data.
	 *
	 * @param Job $job The job instance to update.
	 * @param array $data The data containing job details.
	 */
	private function updateJobDetails(Job $job, array $data)
	{
		$user = $job->user()->get()->first();

		// Update user email and reference
		$job->user_email = @$data['user_email'];
		$job->reference = isset($data['reference']) ? $data['reference'] : '';

		if (isset($data['address'])) {
			// Update job address, instructions, and town
			$job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
			$job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
			$job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
		}

		// Save the updated job
		$job->save();
	}

	/**
	 * Send email notification for the job.
	 *
	 * @param Job $job The job instance.
	 */
	private function sendJobEmailNotification(Job $job)
	{
		$user = $job->user()->get()->first();

		// Determine email and name based on user's email or name
		$email = !empty($job->user_email) ? $job->user_email : $user->email;
		$name = $user->name;

		// Prepare email subject and data
		$subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
		$sendData = [
			'user' => $user,
			'job'  => $job
		];

		// Send email notification
		$this->mailer->send($email, $name, $subject, 'emails.job-created', $sendData);
	}

    /**
	 * Convert job details to data format for Push notification.
	 *
	 * @param Job $job The job instance to be converted.
	 * @return array The job details in data format.
	 */
	public function jobToData(Job $job)
	{
		$data = [];

		// Save job's information to data for sending Push
		$data['job_id'] = $job->id;
		$data['from_language_id'] = $job->from_language_id;
		$data['immediate'] = $job->immediate;
		$data['duration'] = $job->duration;
		$data['status'] = $job->status;
		$data['gender'] = $job->gender;
		$data['certified'] = $job->certified;
		$data['due'] = $job->due;
		$data['job_type'] = $job->job_type;
		$data['customer_phone_type'] = $job->customer_phone_type;
		$data['customer_physical_type'] = $job->customer_physical_type;
		$data['customer_town'] = $job->town;
		$data['customer_type'] = $job->user->userMeta->customer_type;

		// Extract due date and time from the 'due' field
		list($dueDate, $dueTime) = explode(" ", $job->due);
		$data['due_date'] = $dueDate;
		$data['due_time'] = $dueTime;

		// Determine job type for job_for field
		$data['job_for'] = $this->getJobForValues($job);

		return $data;
	}

	/**
	 * Get job for values based on gender and certified fields.
	 *
	 * @param Job $job The job instance.
	 * @return array The job for values.
	 */
	private function getJobForValues(Job $job)
	{
		$jobFor = [];

		if ($job->gender != null) {
			$genderValue = ($job->gender == 'male') ? 'Man' : 'Kvinna';
			$jobFor[] = $genderValue;
		}

		if ($job->certified != null) {
			$certifiedValue = $this->getCertifiedValue($job->certified);
			$jobFor[] = $certifiedValue;
		}

		return $jobFor;
	}

	/**
	 * Get the certified value based on different scenarios.
	 *
	 * @param string $certified The certified value from the job instance.
	 * @return string The corresponding certified value.
	 */
	private function getCertifiedValue($certified)
	{
		$certifiedValues = [
			'both' => ['Godkänd tolk', 'Auktoriserad'],
			'yes' => 'Auktoriserad',
			'n_health' => 'Sjukvårdstolk',
			'law' => 'Rättstolk',
			'n_law' => 'Rättstolk',
		];

		return $certifiedValues[$certified] ?? $certified;
	}

    /**
	 * Mark a job as completed and send notifications.
	 *
	 * @param array $post_data The post data containing job information.
	 * @return void
	 */
	public function jobEnd(array $post_data)
	{
		$completedDate = now();
		$jobId = $post_data["job_id"];
		$job = Job::with('translatorJobRel')->find($jobId);

		$sessionTime = $this->calculateSessionTime($job->due, $completedDate);
		
		$this->updateJobStatusAndTime($job, $completedDate, $sessionTime);

		$this->sendSessionEndEmails($job, $post_data['userid'], $sessionTime);

		$this->updateTranslatorRelation($job, $completedDate, $post_data['userid']);
	}

	/**
	 * Calculate the session time between two dates.
	 *
	 * @param string $start The start date in Y-m-d H:i:s format.
	 * @param string $end The end date in Y-m-d H:i:s format.
	 * @return string The formatted session time.
	 */
	private function calculateSessionTime($start, $end)
	{
		$startTime = date_create($start);
		$endTime = date_create($end);
		$diff = date_diff($endTime, $startTime);

		return $diff->format('%h tim %i min');
	}

	/**
	 * Update job status and session time.
	 *
	 * @param Job $job The job instance to be updated.
	 * @param string $completedDate The completed date in Y-m-d H:i:s format.
	 * @param string $sessionTime The formatted session time.
	 * @return void
	 */
	private function updateJobStatusAndTime(Job $job, $completedDate, $sessionTime)
	{
		$job->end_at = $completedDate;
		$job->status = 'completed';
		$job->session_time = $sessionTime;
		$job->save();
	}

	/**
	 * Send session end emails and fire events.
	 *
	 * @param Job $job The job instance.
	 * @param int $userId The user ID.
	 * @param string $sessionTime The formatted session time.
	 * @return void
	 */
	private function sendSessionEndEmails(Job $job, $userId, $sessionTime)
	{
		$user = $job->user;
		$forText = ($userId == $job->user_id) ? 'faktura' : 'lön';

		$this->sendSessionEndEmail($user, $job, $sessionTime, $forText);

		$translatorRelation = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
		$translatorUser = $translatorRelation->user;

		$this->sendSessionEndEmail($translatorUser, $job, $sessionTime, $forText);

		Event::fire(new SessionEnded($job, $userId));
		
		$translatorRelation->update([
			'completed_at' => now(),
			'completed_by' => $userId,
		]);
	}

	/**
	 * Send session end email to a user.
	 *
	 * @param User $user The user instance.
	 * @param Job $job The job instance.
	 * @param string $sessionTime The formatted session time.
	 * @param string $forText The context of the email.
	 * @return void
	 */
	private function sendSessionEndEmail(User $user, Job $job, $sessionTime, $forText)
	{
		$subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

		$data = [
			'user' => $user,
			'job' => $job,
			'session_time' => $sessionTime,
			'for_text' => $forText,
		];

		$this->mailer->send($user->email, $user->name, $subject, 'emails.session-ended', $data);
	}

    /**
	 * Get potential job IDs based on user's attributes.
	 *
	 * @param int $user_id The ID of the user.
	 * @return array An array of potential job objects.
	 */
	public function getPotentialJobsWithUserId($user_id)
	{
		// Retrieve user meta information
		$user_meta = UserMeta::where('user_id', $user_id)->first();
		$translator_type = $user_meta->translator_type;

		// Determine job type based on translator type
		$job_type = $this->determineJobType($translator_type);

		// Get user's languages
		$user_languages = UserLanguages::where('user_id', $user_id)->pluck('lang_id')->toArray();

		// Retrieve other user attributes
		$gender = $user_meta->gender;
		$translator_level = $user_meta->translator_level;

		// Retrieve potential job IDs based on user attributes
		$potential_job_ids = Job::getJobs($user_id, $job_type, 'pending', $user_languages, $gender, $translator_level);

		// Filter jobs based on translator's town and customer preferences
		$filtered_job_ids = $this->filterJobsByLocationAndPreferences($potential_job_ids, $user_id);

		// Convert job IDs to job objects
		$potential_jobs = TeHelper::convertJobIdsInObjs($filtered_job_ids);

		return $potential_jobs;
	}

	/**
	 * Determine the job type based on translator type.
	 *
	 * @param string $translator_type The translator type.
	 * @return string The determined job type.
	 */
	private function determineJobType($translator_type)
	{
		if ($translator_type == 'professional') {
			return 'paid';
		} elseif ($translator_type == 'rwstranslator') {
			return 'rws';
		} else {
			return 'unpaid';
		}
	}

	/**
	 * Filter jobs by translator's town and customer preferences.
	 *
	 * @param array $job_ids The array of job IDs.
	 * @param int $translator_id The ID of the translator.
	 * @return array The filtered job IDs.
	 */
	private function filterJobsByLocationAndPreferences($job_ids, $translator_id)
	{
		$filtered_job_ids = [];

		foreach ($job_ids as $job_id) {
			$job = Job::find($job_id->id);
			$job_user_id = $job->user_id;

			$is_same_town = Job::checkTowns($job_user_id, $translator_id);
			$customer_phone_type = $job->customer_phone_type;
			$customer_physical_type = $job->customer_physical_type;

			if (($customer_phone_type == 'no' || $customer_phone_type == '') && $customer_physical_type == 'yes' && !$is_same_town) {
				continue;
			}

			$filtered_job_ids[] = $job_id;
		}

		return $filtered_job_ids;
	}

    /**
	 * Send push notifications to suitable translators for a job.
	 *
	 * @param Job $job The job for which notifications are sent.
	 * @param array $data Additional job data.
	 * @param int $exclude_user_id The user ID to exclude from notifications.
	 */
	public function sendNotificationToTranslators(Job $job, array $data, int $exclude_user_id)
	{
		$translator_array = [];
		$delayed_translator_array = [];

		$translators = User::where('user_type', '2')
							->where('status', '1')
							->where('id', '!=', $exclude_user_id)
							->get();

		foreach ($translators as $translator) {
			if (!$this->isNeedToSendPush($translator->id)) {
				continue;
			}

			$not_get_emergency = TeHelper::getUsermeta($translator->id, 'not_get_emergency');

			if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') {
				continue;
			}

			$potential_jobs = $this->getPotentialJobsWithUserId($translator->id);

			foreach ($potential_jobs as $potential_job) {
				if ($job->id == $potential_job->id) {
					$job_for_translator = Job::assignedToPaticularTranslator($translator->id, $potential_job->id);

					if ($job_for_translator == 'SpecificJob') {
						$job_checker = Job::checkParticularJob($translator->id, $potential_job);

						if ($job_checker != 'userCanNotAcceptJob') {
							if ($this->isNeedToDelayPush($translator->id)) {
								$delayed_translator_array[] = $translator;
							} else {
								$translator_array[] = $translator;
							}
						}
					}
				}
			}
		}

		$data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
		$data['notification_type'] = 'suitable_job';

		$msg_contents = ($data['immediate'] == 'no')
			? "Ny bokning för {$data['language']} tolk {$data['duration']} min {$data['due']}"
			: "Ny akutbokning för {$data['language']} tolk {$data['duration']} min";

		$msg_text = [
			'en' => $msg_contents
		];

		$logger = new Logger('push_logger');
		$logger->pushHandler(new StreamHandler(storage_path("logs/push/laravel-" . date('Y-m-d') . ".log"), Logger::DEBUG));
		$logger->pushHandler(new FirePHPHandler());
		$logger->addInfo('Push send for job ' . $job->id, [
			'translator_array' => $translator_array,
			'delayed_translator_array' => $delayed_translator_array,
			'msg_text' => $msg_text,
			'data' => $data
		]);

		$this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);
		$this->sendPushNotificationToSpecificUsers($delayed_translator_array, $job->id, $data, $msg_text, true);
	}

    /**
	 * Sends SMS notifications to potential translators for a job.
	 *
	 * @param Job $job The job for which notifications are sent.
	 * @return int The count of translators who received the SMS.
	 */
	public function sendSMSNotificationToTranslators(Job $job)
	{
		$translators = $this->getPotentialTranslators($job);
		$jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

		// Prepare message templates
		$date = date('d.m.Y', strtotime($job->due));
		$time = date('H:i', strtotime($job->due));
		$duration = $this->convertToHoursMins($job->duration);
		$jobId = $job->id;
		$city = $job->city ?? $jobPosterMeta->city;

		$phoneJobMessageTemplate = trans('sms.phone_job', compact('date', 'time', 'duration', 'jobId'));

		$physicalJobMessageTemplate = trans('sms.physical_job', compact('date', 'time', 'city', 'duration', 'jobId'));

		// Analyze whether it's phone or physical; if both = default to phone
		if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
			// It's a physical job
			$message = $physicalJobMessageTemplate;
		} else if ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
			// It's a phone job
			$message = $phoneJobMessageTemplate;
		} else if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
			// It's both, but should be handled as phone job
			$message = $phoneJobMessageTemplate;
		} else {
			// This shouldn't be feasible, so no handling of this edge case
			$message = '';
		}

		Log::info($message);

		// Send messages via SMS handler
		foreach ($translators as $translator) {
			// Send message to translator
			$status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
			Log::info("Send SMS to {$translator->email} ({$translator->mobile}), status: " . print_r($status, true));
		}

		return count($translators);
	}

    /**
	 * Checks if push notifications need to be delayed during night time for a user.
	 *
	 * @param int $user_id The ID of the user to check.
	 * @return bool Returns true if push notifications need to be delayed, false otherwise.
	 */
	public function isNeedToDelayPush($user_id)
	{
		if (!DateTimeHelper::isNightTime()) {
			return false;
		}

		$not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
		return $not_get_nighttime === 'yes';
	}

	/**
	 * Checks if push notifications need to be sent to a user.
	 *
	 * @param int $user_id The ID of the user to check.
	 * @return bool Returns true if push notifications need to be sent, false otherwise.
	 */
	public function isNeedToSendPush($user_id)
	{
		$not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
		return $not_get_notification !== 'yes';
	}

    /**
	 * Sends OneSignal Push Notifications to specific users with user-tags.
	 *
	 * @param array $users List of users to send notifications to.
	 * @param int $job_id The ID of the job.
	 * @param array $data Data for the push notification.
	 * @param array $msg_text The message text for the push notification.
	 * @param bool $is_need_delay Indicates whether the push notification needs to be delayed.
	 * @return void
	 */
	public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
	{
		$logger = new Logger('push_logger');
		$logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
		$logger->pushHandler(new FirePHPHandler());
		$logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

		$onesignalAppID = config('app.' . (env('APP_ENV') == 'prod' ? 'prod' : 'dev') . 'OnesignalAppID');
		$onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.' . (env('APP_ENV') == 'prod' ? 'prod' : 'dev') . 'OnesignalApiKey'));

		$user_tags = $this->getUserTagsStringFromArray($users);

		$data['job_id'] = $job_id;
		$android_sound = $data['notification_type'] === 'suitable_job' && $data['immediate'] === 'no' ? 'normal_booking' : 'emergency_booking';
		$ios_sound = $data['notification_type'] === 'suitable_job' && $data['immediate'] === 'no' ? 'normal_booking.mp3' : 'emergency_booking.mp3';

		$fields = [
			'app_id'         => $onesignalAppID,
			'tags'           => json_decode($user_tags),
			'data'           => $data,
			'title'          => ['en' => 'DigitalTolk'],
			'contents'       => $msg_text,
			'ios_badgeType'  => 'Increase',
			'ios_badgeCount' => 1,
			'android_sound'  => $android_sound,
			'ios_sound'      => $ios_sound
		];

		if ($is_need_delay) {
			$next_business_time = DateTimeHelper::getNextBusinessTimeString();
			$fields['send_after'] = $next_business_time;
		}

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $onesignalRestAuthKey]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		curl_setopt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		$response = curl_exec($ch);
		$logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
		curl_close($ch);
	}

    /**
	 * Get potential translators for the given job.
	 *
	 * @param Job $job The job for which potential translators are being retrieved.
	 * @return \Illuminate\Support\Collection The collection of potential translators.
	 */
	public function getPotentialTranslators(Job $job)
	{
		$jobTypeTranslatorMapping = [
			'paid' => 'professional',
			'rws' => 'rwstranslator',
			'unpaid' => 'volunteer'
		];

		$translator_type = $jobTypeTranslatorMapping[$job->job_type] ?? 'unknown';

		$joblanguage = $job->from_language_id;
		$gender = $job->gender;

		$translator_level = $this->getTranslatorLevels($job->certified);

		$translatorsId = UsersBlacklist::where('user_id', $job->user_id)->pluck('translator_id')->all();

		$users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $translatorsId);

		return $users;
	}

	/**
	 * Get translator levels based on certification status.
	 *
	 * @param string|null $certified Certification status of the job.
	 * @return array Array of translator levels.
	 */
	private function getTranslatorLevels($certified)
	{
		$translator_levels = [];

		if (!empty($certified)) {
			if ($certified == 'yes' || $certified == 'both') {
				$translator_levels[] = 'Certified';
				$translator_levels[] = 'Certified with specialisation in law';
				$translator_levels[] = 'Certified with specialisation in health care';
			} elseif ($certified == 'law' || $certified == 'n_law') {
				$translator_levels[] = 'Certified with specialisation in law';
			} elseif ($certified == 'health' || $certified == 'n_health') {
				$translator_levels[] = 'Certified with specialisation in health care';
			} elseif ($certified == 'normal' || $certified == 'both') {
				$translator_levels[] = 'Layman';
				$translator_levels[] = 'Read Translation courses';
			} elseif (is_null($certified)) {
				$translator_levels[] = 'Certified';
				$translator_levels[] = 'Certified with specialisation in law';
				$translator_levels[] = 'Certified with specialisation in health care';
				$translator_levels[] = 'Layman';
				$translator_levels[] = 'Read Translation courses';
			}
		}

		return $translator_levels;
	}

    /**
	 * Update the job with the provided data.
	 *
	 * @param int $id The ID of the job to be updated.
	 * @param array $data The data to update the job with.
	 * @param User $cuser The current user performing the update.
	 * @return array|string Result of the job update.
	 */
	public function updateJob($id, $data, $cuser)
	{
		// Fetch the job to be updated
		$job = Job::find($id);

		// Find the current translator associated with the job
		$current_translator = $this->findCurrentTranslator($job);

		// Initialize log data array
		$log_data = [];

		// Initialize flags for changes
		$langChanged = false;
		$dateChanged = false;
		$translatorChanged = false;

		// Check and handle translator change
		$changeTranslator = $this->changeTranslator($current_translator, $data, $job);
		if ($changeTranslator['translatorChanged']) {
			$log_data[] = $changeTranslator['log_data'];
			$translatorChanged = true;
		}

		// Check and handle due date change
		$changeDue = $this->changeDue($job->due, $data['due']);
		if ($changeDue['dateChanged']) {
			$old_time = $job->due;
			$job->due = $data['due'];
			$log_data[] = $changeDue['log_data'];
			$dateChanged = true;
		}

		// Check and handle language change
		if ($job->from_language_id != $data['from_language_id']) {
			$log_data[] = [
				'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
				'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
			];
			$old_lang = $job->from_language_id;
			$job->from_language_id = $data['from_language_id'];
			$langChanged = true;
		}

		// Check and handle status change
		$changeStatus = $this->changeStatus($job, $data, $translatorChanged);
		if ($changeStatus['statusChanged']) {
			$log_data[] = $changeStatus['log_data'];
		}

		// Update admin comments and reference
		$job->admin_comments = $data['admin_comments'];
		$job->reference = $data['reference'];

		// Save the job changes
		$job->save();

		// Handle notifications if applicable
		if ($job->due <= Carbon::now()) {
			return ['Updated'];
		} else {
			if ($dateChanged) {
				$this->sendChangedDateNotification($job, $old_time);
			}
			if ($translatorChanged) {
				$this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
			}
			if ($langChanged) {
				$this->sendChangedLangNotification($job, $old_lang);
			}
		}
	}

    /**
	 * Change the status of the job based on the provided data.
	 *
	 * @param Job $job The job to change the status for.
	 * @param array $data The data containing the new status.
	 * @param bool $changedTranslator Whether the translator was changed.
	 * @return array|null Result of the status change.
	 */
	private function changeStatus($job, $data, $changedTranslator)
	{
		$oldStatus = $job->status;
		$statusChanged = false;

		// Check if the status has changed
		if ($oldStatus !== $data['status']) {
			// Switch based on the old status to determine action
			switch ($oldStatus) {
				case 'timedout':
					$statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
					break;
				case 'completed':
					$statusChanged = $this->changeCompletedStatus($job, $data);
					break;
				case 'started':
					$statusChanged = $this->changeStartedStatus($job, $data);
					break;
				case 'pending':
					$statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
					break;
				case 'withdrawafter24':
					$statusChanged = $this->changeWithdrawafter24Status($job, $data);
					break;
				case 'assigned':
					$statusChanged = $this->changeAssignedStatus($job, $data);
					break;
				default:
					// Default case, status change not supported
					break;
			}

			// If the status change is successful, prepare log data
			if ($statusChanged) {
				$logData = [
					'old_status' => $oldStatus,
					'new_status' => $data['status']
				];
				return ['statusChanged' => true, 'log_data' => $logData];
			}
		}

		return null;
	}

    /**
	 * Change the status of the job to 'timedout'.
	 *
	 * @param Job $job The job to update the status for.
	 * @param array $data The data containing the new status.
	 * @param bool $changedTranslator Whether the translator was changed.
	 * @return bool Whether the status change was successful.
	 */
	private function changeTimedoutStatus($job, $data, $changedTranslator)
	{
		// Check if the status can be changed to 'timedout'
		// (Add necessary conditions here)

		// Save the old status for reference
		$oldStatus = $job->status;

		// Update the status to 'timedout'
		$job->status = $data['status'];

		// Get user details for email
		$user = $job->user()->first();
		$email = !empty($job->user_email) ? $job->user_email : $user->email;
		$name = $user->name;
		$dataEmail = [
			'user' => $user,
			'job'  => $job
		];

		if ($data['status'] == 'pending') {
			
			$job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators
			
			return true;
		} elseif ($changedTranslator) {
			
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
			
			return true;
		}

		// Status change not applicable, no action taken
		return false;
	}


    /**
	 * Change the status of the job to 'completed'.
	 *
	 * @param Job $job The job to update the status for.
	 * @param array $data The data containing the new status.
	 * @return bool Whether the status change was successful.
	 */
	private function changeCompletedStatus($job, $data)
	{
		// Check if the status can be changed to 'completed'
		// (Add necessary conditions here)

		// Update the status to 'completed'
		$job->status = $data['status'];

		if ($data['status'] == 'timedout') {
			// Handle status change with 'timedout' status
			// Check and update admin comments
			if ($data['admin_comments'] == '') {
				return false; // Admin comments required for 'timedout' status
			}
			$job->admin_comments = $data['admin_comments'];
		}

		// Save the updated job
		$job->save();
		return true;
	}


    /**
	 * Change the status of the job to 'started'.
	 *
	 * @param Job $job The job to update the status for.
	 * @param array $data The data containing the new status and session details.
	 * @return bool Whether the status change was successful.
	 */
	private function changeStartedStatus($job, $data)
	{
		// Check if the status can be changed to 'started'
		// (Add necessary conditions here)

		// Update the status to 'started'
		$job->status = $data['status'];

		// Check if admin comments are provided
		if ($data['admin_comments'] == '') {
			return false;
		}
		$job->admin_comments = $data['admin_comments'];

		// Check if the status is being changed to 'completed'
		if ($data['status'] == 'completed') {
			// Get the user and session details
			$user = $job->user()->first();
			$interval = $data['session_time'];
			$diff = explode(':', $interval);
			$session_time = $diff[0] . ' tim ' . $diff[1] . ' min';

			// Prepare email data for customer
			$email = !empty($job->user_email) ? $job->user_email : $user->email;
			$name = $user->name;
			$dataEmail = [
				'user'         => $user,
				'job'          => $job,
				'session_time' => $session_time,
				'for_text'     => 'faktura'
			];

			// Send email to customer
			$subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
			$this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

			// Prepare email data for translator
			$user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
			$email = $user->user->email;
			$name = $user->user->name;
			$dataEmail = [
				'user'         => $user,
				'job'          => $job,
				'session_time' => $session_time,
				'for_text'     => 'lön'
			];

			// Send email to translator
			$subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
			$this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
		}

		// Save the updated job
		$job->save();
		return true;
	}


    /**
	 * Change the status of the job to 'pending'.
	 *
	 * @param Job $job The job to update the status for.
	 * @param array $data The data containing the new status and other details.
	 * @param bool $changedTranslator Whether the translator was changed.
	 * @return bool Whether the status change was successful.
	 */
	private function changePendingStatus($job, $data, $changedTranslator)
	{
		// Check if the status can be changed to 'pending'
		// (Add necessary conditions here)

		// Update the status to 'pending'
		$job->status = $data['status'];

		// Check if admin comments are provided
		if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
			return false;
		}
		$job->admin_comments = $data['admin_comments'];

		// Get user details
		$user = $job->user()->first();
		$email = !empty($job->user_email) ? $job->user_email : $user->email;
		$name = $user->name;
		$dataEmail = [
			'user' => $user,
			'job'  => $job
		];

		// Check if the status is being changed to 'assigned' and the translator was changed
		if ($data['status'] == 'assigned' && $changedTranslator) {
			// Save the job
			$job->save();

			// Prepare email data
			$job_data = $this->jobToData($job);
			$subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
			$this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

			// Get translator details and send email to them
			$translator = Job::getJobsAssignedTranslatorDetail($job);
			$this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

			// Send session start reminders to user and translator
			$language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
			$this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
			$this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);

			return true;
		} else {
			// Send email for status change from 'pending' or 'assigned'
			$subject = 'Avbokning av bokningsnr: #' . $job->id;
			$this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

			// Save the job
			$job->save();
			return true;
		}

		return false;
	}


    /**
	 * Send session start reminder notification to a user.
	 *
	 * @param User $user The user to send the notification to.
	 * @param Job $job The job for which the session start is being reminded.
	 * @param string $language The language of the session.
	 * @param string $due The due date and time of the session.
	 * @param int $duration The duration of the session in minutes.
	 * @return void
	 */
	public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
	{
		// Initialize the logger
		$this->initializeLogger();

		// Prepare data for notification
		$data = array();
		$data['notification_type'] = 'session_start_remind';
		
		$due_explode = explode(' ', $due);
		$msg_text = array(
			"en" => $this->generateSessionStartReminderMessage($language, $due_explode, $job->town, $duration, $job->customer_physical_type)
		);

		// Check if push notification is needed
		if ($this->bookingRepository->isNeedToSendPush($user->id)) {
			$users_array = array($user);
			$this->bookingRepository->sendPushNotificationToSpecificUsers(
				$users_array,
				$job->id,
				$data,
				$msg_text,
				$this->bookingRepository->isNeedToDelayPush($user->id)
			);
			$this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
		}
	}

	/**
	 * Generate the session start reminder message.
	 *
	 * @param string $language The language of the session.
	 * @param array $due_explode The array containing date and time components of the due datetime.
	 * @param string $town The town of the session.
	 * @param int $duration The duration of the session in minutes.
	 * @param string $customer_physical_type The type of session (physical or phone).
	 * @return string The generated reminder message.
	 */
	private function generateSessionStartReminderMessage($language, $due_explode, $town, $duration, $customer_physical_type)
	{
		$session_type = $customer_physical_type == 'yes' ? 'på plats i ' . $town : 'telefon';
		return sprintf(
			"Detta är en påminnelse om att du har en %s tolkning (%s) kl %s på %s som varar i %s min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!",
			$language,
			$session_type,
			$due_explode[1],
			$due_explode[0],
			$duration
		);
	}

	/**
	 * Initialize the logger.
	 *
	 * @return void
	 */
	private function initializeLogger()
	{
		$this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
		$this->logger->pushHandler(new FirePHPHandler());
	}


    /**
	 * Change job status to 'withdrawafter24' if applicable.
	 *
	 * @param Job $job The job to update the status for.
	 * @param array $data The data containing the new status and admin comments.
	 * @return bool Whether the status was changed or not.
	 */
	private function changeWithdrawafter24Status($job, $data)
	{
		if ($data['status'] === 'withdrawafter24') {
			$job->status = $data['status'];
			if ($data['admin_comments'] === '') {
				return false;
			}
			$job->admin_comments = $data['admin_comments'];
			$job->save();
			return true;
		}
		return false;
	}

	/**
	 * Change job status to 'assigned' if applicable.
	 *
	 * @param Job $job The job to update the status for.
	 * @param array $data The data containing the new status and admin comments.
	 * @return bool Whether the status was changed or not.
	 */
	private function changeAssignedStatus($job, $data)
	{
		$validStatuses = ['withdrawbefore24', 'withdrawafter24', 'timedout'];

		if (in_array($data['status'], $validStatuses)) {
			$job->status = $data['status'];
			if ($data['admin_comments'] === '' && $data['status'] === 'timedout') {
				return false;
			}
			$job->admin_comments = $data['admin_comments'];
			
			if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
				$this->sendCustomerCancellationNotification($job);
				$this->sendTranslatorCancellationNotification($job);
			}

			$job->save();
			return true;
		}
		return false;
	}

	/**
	 * Send cancellation notification to the customer.
	 *
	 * @param Job $job The job for which the cancellation is happening.
	 * @return void
	 */
	private function sendCustomerCancellationNotification($job)
	{
		$user = $job->user()->first();
		$email = $job->user_email ?: $user->email;
		$name = $user->name;
		$dataEmail = [
			'user' => $user,
			'job'  => $job
		];
		$subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
		$this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
	}

	/**
	 * Send cancellation notification to the translator.
	 *
	 * @param Job $job The job for which the cancellation is happening.
	 * @return void
	 */
	private function sendTranslatorCancellationNotification($job)
	{
		$user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
		$email = $user->user->email;
		$name = $user->user->name;
		$dataEmail = [
			'user' => $user,
			'job'  => $job
		];
		$subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
		$this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
	}


    /**
	 * Change the translator for the job if applicable.
	 *
	 * @param Translator|null $current_translator The current translator associated with the job.
	 * @param array $data The data containing the new translator's ID or email.
	 * @param Job $job The job to update.
	 * @return array The result of the translator change and associated data.
	 */
	private function changeTranslator($current_translator, $data, $job)
	{
		$translatorChanged = false;
		$log_data = [];

		if (!empty($current_translator) || (!empty($data['translator']) && $data['translator'] != 0) || !empty($data['translator_email'])) {
			if (!empty($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || !empty($data['translator_email'])) && (!empty($data['translator']) && $data['translator'] != 0)) {
				$new_translator = $this->createNewTranslatorFromCurrent($current_translator, $data['translator']);
				$this->cancelCurrentTranslator($current_translator);
				$log_data[] = $this->getLogDataForTranslatorChange($current_translator->user->email, $new_translator->user->email);
				$translatorChanged = true;
			} elseif (empty($current_translator) && !empty($data['translator']) && ($data['translator'] != 0 || !empty($data['translator_email']))) {
				$new_translator = $this->createNewTranslatorForJob($data['translator'], $job);
				$log_data[] = $this->getLogDataForTranslatorChange(null, $new_translator->user->email);
				$translatorChanged = true;
			}

			if ($translatorChanged) {
				return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
			}
		}

		return ['translatorChanged' => $translatorChanged];
	}

	/**
	 * Create a new translator from the current translator's data.
	 *
	 * @param Translator $current_translator The current translator.
	 * @param int $newUserId The ID of the new user to become the translator.
	 * @return Translator The newly created translator.
	 */
	private function createNewTranslatorFromCurrent($current_translator, $newUserId)
	{
		$new_translator_data = $current_translator->toArray();
		$new_translator_data['user_id'] = $newUserId;
		unset($new_translator_data['id']);
		return Translator::create($new_translator_data);
	}

	/**
	 * Cancel the current translator by setting the cancel_at timestamp.
	 *
	 * @param Translator $current_translator The current translator.
	 * @return void
	 */
	private function cancelCurrentTranslator($current_translator)
	{
		$current_translator->cancel_at = Carbon::now();
		$current_translator->save();
	}

	/**
	 * Get the log data for a translator change.
	 *
	 * @param string|null $old_translator_email The email of the old translator.
	 * @param string $new_translator_email The email of the new translator.
	 * @return array The log data.
	 */
	private function getLogDataForTranslatorChange($old_translator_email, $new_translator_email)
	{
		return [
			'old_translator' => $old_translator_email,
			'new_translator' => $new_translator_email
		];
	}

	/**
	 * Change the due date of the job if it has changed.
	 *
	 * @param string $old_due The old due date.
	 * @param string $new_due The new due date.
	 * @return array The result of the due date change and associated log data.
	 */
	private function changeDue($old_due, $new_due)
	{
		$dateChanged = false;

		if ($old_due != $new_due) {
			$log_data = [
				'old_due' => $old_due,
				'new_due' => $new_due
			];
			$dateChanged = true;
			return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
		}

		return ['dateChanged' => $dateChanged];
	}


    /**
	 * Send notifications about changed translators.
	 *
	 * @param Job $job The job for which the translator has been changed.
	 * @param Translator|null $current_translator The current translator being replaced.
	 * @param Translator $new_translator The new translator being assigned.
	 * @return void
	 */
	public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
	{
		$user = $job->user()->first();
		$email = !empty($job->user_email) ? $job->user_email : $user->email;
		$name = $user->name;
		$subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag #' . $job->id;

		$data = [
			'user' => $user,
			'job' => $job
		];

		// Notify the customer about the changed translator
		$this->sendEmailNotification($email, $name, $subject, 'emails.job-changed-translator-customer', $data);

		if ($current_translator) {
			// Notify the old translator if there was a replacement
			$this->sendEmailNotification($current_translator->user->email, $current_translator->user->name, $subject, 'emails.job-changed-translator-old-translator', $data);
		}

		// Notify the new translator
		$this->mailer->send($new_translator->user->email, $new_translator->user->name, $subject, 'emails.job-changed-translator-new-translator', $data);
	}


    /**
	 * Send notifications about changed booking date.
	 *
	 * @param Job $job The job for which the booking date has been changed.
	 * @param string $old_time The previous booking due time.
	 * @return void
	 */
	public function sendChangedDateNotification($job, $old_time)
	{
		$user = $job->user()->first();
		$email = !empty($job->user_email) ? $job->user_email : $user->email;
		$name = $user->name;
		$subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;

		$data = [
			'user' => $user,
			'job' => $job,
			'old_time' => $old_time
		];

		// Notify the customer about the changed booking date
		$this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);
		

		// Notify the assigned translator
		$translator = Job::getJobsAssignedTranslatorDetail($job);
		$translatorData = [
			'user' => $translator,
			'job' => $job,
			'old_time' => $old_time
		];
		$this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $translatorData);
	}


    /**
	 * Send notifications about changed booking language.
	 *
	 * @param Job $job The job for which the booking language has been changed.
	 * @param string $old_lang The previous booking language.
	 * @return void
	 */
	public function sendChangedLangNotification($job, $old_lang)
	{
		$user = $job->user()->first();
		$email = !empty($job->user_email) ? $job->user_email : $user->email;
		$name = $user->name;
		$subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;

		$data = [
			'user' => $user,
			'job' => $job,
			'old_lang' => $old_lang
		];

		// Notify the customer about the changed booking language
		$this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);

		// Notify the assigned translator
		$translator = Job::getJobsAssignedTranslatorDetail($job);
		$this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
	}

	/**
	 * Send Job Expired Push Notification.
	 *
	 * @param Job $job The expired job.
	 * @param User $user The user to receive the notification.
	 * @return void
	 */
	public function sendExpiredNotification($job, $user)
	{
		$data = [];
		$data['notification_type'] = 'job_expired';
		$language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
		$msg_text = [
			"en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
		];

		if ($this->isNeedToSendPush($user->id)) {
			$users_array = [$user];
			$this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
		}
	}


    /**
	 * Send notification to suitable translators when admin cancels a job.
	 *
	 * @param int $job_id The ID of the job being canceled by admin.
	 * @return void
	 */
	public function sendNotificationByAdminCancelJob($job_id)
	{
		$job = Job::findOrFail($job_id);
		$user_meta = $job->user->userMeta()->first();

		// Prepare job information for notification data
		$data = [
			'job_id' => $job->id,
			'from_language_id' => $job->from_language_id,
			'immediate' => $job->immediate,
			'duration' => $job->duration,
			'status' => $job->status,
			'gender' => $job->gender,
			'certified' => $job->certified,
			'due' => $job->due,
			'job_type' => $job->job_type,
			'customer_phone_type' => $job->customer_phone_type,
			'customer_physical_type' => $job->customer_physical_type,
			'customer_town' => $user_meta->city,
			'customer_type' => $user_meta->customer_type,
		];

		$due_Date = explode(" ", $job->due);
		$due_date = $due_Date[0];
		$due_time = $due_Date[1];
		$data['due_date'] = $due_date;
		$data['due_time'] = $due_time;

		// Prepare job preferences for notification data
		$data['job_for'] = [];
		if ($job->gender != null) {
			$data['job_for'][] = ucfirst($job->gender);
		}
		if ($job->certified != null) {
			if ($job->certified == 'both') {
				$data['job_for'][] = 'Normal';
				$data['job_for'][] = 'Certified';
			} else {
				$data['job_for'][] = ucfirst($job->certified);
			}
		}

		// Send notification to suitable translators
		$this->sendNotificationTranslator($job, $data, '*');
	}


    /**
	 * Send session start remind notification.
	 *
	 * @param User $user The user to send the notification to.
	 * @param Job $job The job for which the notification is being sent.
	 * @param string $language The language for the job.
	 * @param string $due The due date and time of the job.
	 * @param string $duration The duration of the job.
	 * @return void
	 */
	private function sendNotificationChangePending($user, $job, $language, $due, $duration)
	{
		$data = [
			'notification_type' => 'session_start_remind'
		];

		if ($job->customer_physical_type == 'yes') {
			$msg_text = [
				"en" => "Du har nu fått platstolkningen för {$language} kl {$duration} den {$due}. Vänligen säkerställ att du är förberedd för den tiden. Tack!"
			];
		} else {
			$msg_text = [
				"en" => "Du har nu fått telefontolkningen för {$language} kl {$duration} den {$due}. Vänligen säkerställ att du är förberedd för den tiden. Tack!"
			];
		}

		if ($this->bookingRepository->isNeedToSendPush($user->id)) {
			$users_array = [$user];
			$this->bookingRepository->sendPushNotificationToSpecificUsers(
				$users_array,
				$job->id,
				$data,
				$msg_text,
				$this->bookingRepository->isNeedToDelayPush($user->id)
			);
		}
	}

	/**
	 * Generate user_tags string from users array for creating OneSignal notifications.
	 *
	 * @param array $users An array of users for generating user tags.
	 * @return string
	 */
	private function getUserTagsStringFromArray($users)
	{
		$user_tags = "[";
		$first = true;

		foreach ($users as $oneUser) {
			if ($first) {
				$first = false;
			} else {
				$user_tags .= ',{"operator": "OR"},';
			}
			$user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
		}

		$user_tags .= ']';
		return $user_tags;
	}


    /**
	 * Accept a job assignment for a translator.
	 *
	 * @param array $data The data containing job_id.
	 * @param User $user The user accepting the job.
	 * @return array The response indicating success or failure.
	 */
	public function acceptJob($data, $user)
	{
		// Get admin email and sender email from config
		$adminemail = config('app.admin_email');
		$adminSenderEmail = config('app.admin_sender_email');

		$cuser = $user;
		$job_id = $data['job_id'];
		
		try {
			$job = Job::findOrFail($job_id);
			
			// Check if the translator is available for the job time
			if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
				if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
					$job->status = 'assigned';
					$job->save();
					$user = $job->user()->first();
					
					// Send confirmation email to user
					$this->sendJobAcceptedEmail($job, $user);
					
					$jobs = $this->getPotentialJobs($cuser);
					$response = [
						'list' => json_encode(['jobs' => $jobs, 'job' => $job], true),
						'status' => 'success'
					];
				} else {
					$response = [
						'status' => 'fail',
						'message' => 'Failed to assign the job.'
					];
				}
			} else {
				$response = [
					'status' => 'fail',
					'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'
				];
			}
		} catch (\Exception $e) {
			$response = [
				'status' => 'error',
				'message' => 'An error occurred while processing the request.'
			];
		}

		return $response;
	}

	/**
	 * Send job accepted confirmation email to the user.
	 *
	 * @param Job $job The job that was accepted.
	 * @param User $user The user who accepted the job.
	 * @return void
	 */
	private function sendJobAcceptedEmail($job, $user)
	{
		$email = !empty($job->user_email) ? $job->user_email : $user->email;
		$name = $user->name;
		$subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
		$data = [
			'user' => $user,
			'job'  => $job
		];
		$this->mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
	}


    /**
	 * Accept a job with the given job id.
	 *
	 * @param int $job_id The ID of the job to be accepted.
	 * @param User $cuser The user who is accepting the job.
	 * @return array The response indicating success or failure.
	 */
	public function acceptJobWithId($job_id, $cuser)
	{
		$adminemail = config('app.admin_email');
		$adminSenderEmail = config('app.admin_sender_email');
		
		try {
			$job = Job::findOrFail($job_id);
			$response = [];

			if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
				if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
					$job->status = 'assigned';
					$job->save();
					$user = $job->user()->first();
					$email = !empty($job->user_email) ? $job->user_email : $user->email;
					$name = $user->name;
					$subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
					$data = [
						'user' => $user,
						'job'  => $job
					];
					$this->mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

					// Send push notification to user
					$this->sendJobAcceptedPushNotification($job, $user);

					// Success response
					$language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
					$response = [
						'status' => 'success',
						'list' => ['job' => $job],
						'message' => 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due
					];
				} else {
					// Booking already accepted by someone else
					$response = [
						'status' => 'fail',
						'message' => 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning'
					];
				}
			} else {
				// You already have a booking at the given time
				$response = [
					'status' => 'fail',
					'message' => 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning'
				];
			}
			return $response;
		} catch (\Exception $e) {
			// Handle exceptions and return error response
			return [
				'status' => 'error',
				'message' => 'An error occurred while processing the request.'
			];
		}
	}

	/**
	 * Send push notification to the user for the accepted job.
	 *
	 * @param Job $job The job that was accepted.
	 * @param User $user The user who accepted the job.
	 * @return void
	 */
	private function sendJobAcceptedPushNotification($job, $user)
	{
		$data = [
			'notification_type' => 'job_accepted'
		];
		$language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
		$msg_text = [
			"en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
		];
		if ($this->isNeedToSendPush($user->id)) {
			$users_array = [$user];
			$this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
		}
	}


    /**
	 * Cancel a job through AJAX.
	 *
	 * @param array $data The cancellation data.
	 * @param User $user The user performing the cancellation.
	 * @return array The response indicating success or failure.
	 */
	public function cancelJobAjax($data, $user)
	{
		$response = [];
		
		// Check for 24hrs cancellation logic
		// @todo: Implement the 24hrs cancellation logic here
		
		$cuser = $user;
		$job_id = $data['job_id'];
		$job = Job::findOrFail($job_id);
		$translator = Job::getJobsAssignedTranslatorDetail($job);

		if ($cuser->is('customer')) {
			$job->withdraw_at = Carbon::now();
			if ($job->withdraw_at->diffInHours($job->due) >= 24) {
				$job->status = 'withdrawbefore24';
			} else {
				$job->status = 'withdrawafter24';
			}
			$job->save();
			Event::fire(new JobWasCanceled($job));
			
			// Notify translator
			$this->notifyTranslatorJobCancelled($job, $translator);

			$response['status'] = 'success';
			$response['jobstatus'] = 'success';
		} else {
			if ($job->due->diffInHours(Carbon::now()) > 24) {
				$customer = $job->user()->first();
				if ($customer) {
					// Notify customer
					$this->notifyCustomerJobCancelled($job, $customer);

					$job->status = 'pending';
					$job->created_at = now();
					$job->will_expire_at = TeHelper::willExpireAt($job->due, now());
					$job->save();

					Job::deleteTranslatorJobRel($translator->id, $job_id);

					// Send notification to suitable translators
					$this->sendNotificationToSuitableTranslators($job);

					$response['status'] = 'success';
				} else {
					$response['status'] = 'fail';
					$response['message'] = 'An error occurred while processing the request.';
				}
			} else {
				$response['status'] = 'fail';
				$response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
			}
		}
		return $response;
	}

	/**
	 * Notify the translator about the job cancellation.
	 *
	 * @param Job $job The job that was cancelled.
	 * @param Translator $translator The assigned translator for the job.
	 * @return void
	 */
	private function notifyTranslatorJobCancelled($job, $translator)
	{
		$data = [
			'notification_type' => 'job_cancelled'
		];
		$language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
		$msg_text = [
			"en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
		];
		if ($this->isNeedToSendPush($translator->id)) {
			$users_array = [$translator];
			$this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));
		}
	}

	/**
	 * Notify the customer about the job cancellation.
	 *
	 * @param Job $job The job that was cancelled.
	 * @param User $customer The customer who booked the job.
	 * @return void
	 */
	private function notifyCustomerJobCancelled($job, $customer)
	{
		$data = [
			'notification_type' => 'job_cancelled'
		];
		$language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
		$msg_text = [
			"en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
		];
		if ($this->isNeedToSendPush($customer->id)) {
			$users_array = [$customer];
			$this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));
		}
	}

	/**
	 * Send notification to suitable translators for the job.
	 *
	 * @param Job $job The job for which notification needs to be sent.
	 * @return void
	 */
	private function sendNotificationToSuitableTranslators($job)
	{
		$data = $this->jobToData($job);
		$this->sendNotificationTranslator($job, $data, $translator->id);
	}


    /**
	 * Get potential jobs based on translator type and other criteria.
	 *
	 * @param User $cuser The user for whom potential jobs are to be fetched.
	 * @return array The list of potential jobs.
	 */
	public function getPotentialJobs($cuser)
	{
		$cuser_meta = $cuser->userMeta;
		$job_type = 'unpaid';
		$translator_type = $cuser_meta->translator_type;

		// Determine job type based on translator type
		if ($translator_type == 'professional') {
			$job_type = 'paid';
		} elseif ($translator_type == 'rwstranslator') {
			$job_type = 'rws';
		} elseif ($translator_type == 'volunteer') {
			$job_type = 'unpaid';
		}

		$languages = UserLanguages::where('user_id', $cuser->id)->get();
		$userlanguage = $languages->pluck('lang_id')->all();
		$gender = $cuser_meta->gender;
		$translator_level = $cuser_meta->translator_level;
		
		// Fetch potential job ids based on criteria
		$job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);

		foreach ($job_ids as $k => $job) {
			$jobuserid = $job->user_id;
			$job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
			$job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
			$checktown = Job::checkTowns($jobuserid, $cuser->id);

			// Filter out jobs that are not suitable
			if ($job->specific_job == 'SpecificJob' && $job->check_particular_job == 'userCanNotAcceptJob') {
				unset($job_ids[$k]);
			}

			if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !$checktown) {
				unset($job_ids[$k]);
			}
		}

		return $job_ids;
	}


    /**
	 * End a job session and mark it as completed.
	 *
	 * @param array $post_data The data containing job_id and user_id.
	 * @return array The response indicating the status of the operation.
	 */
	public function endJob($post_data)
	{
		$completeddate = now(); // Use Carbon to get the current date and time
		$jobid = $post_data["job_id"];
		$job_detail = Job::with('translatorJobRel')->find($jobid);

		if ($job_detail->status !== 'started') {
			return ['status' => 'success'];
		}

		$duedate = $job_detail->due;
		$start = now(); // Current time
		$end = Carbon::createFromFormat('Y-m-d H:i:s', $duedate);
		$diff = $end->diff($start);
		$interval = $diff->format('%h:%i:%s');

		$job = $job_detail;
		$job->end_at = $completeddate;
		$job->status = 'completed';
		$job->session_time = $interval;

		$user = $job->user;
		$email = $job->user_email ?? $user->email;
		$name = $user->name;
		$subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
		[$hours, $minutes, $seconds] = explode(':', $job->session_time);
		$session_time = "{$hours} tim {$minutes} min";
		$data = [
			'user'         => $user,
			'job'          => $job,
			'session_time' => $session_time,
			'for_text'     => 'faktura'
		];
		$this->mailer->send$email, $name, $subject, 'emails.session-ended', $data);

		$job->save();

		$tr = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();

		Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

		$user = $tr->user;
		$email = $user->email;
		$name = $user->name;
		$data = [
			'user'         => $user,
			'job'          => $job,
			'session_time' => $session_time,
			'for_text'     => 'lön'
		];
		$this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);

		$tr->completed_at = $completeddate;
		$tr->completed_by = $post_data['user_id'];
		$tr->save();

		return ['status' => 'success'];
	}



    /**
	 * Mark a job as not carried out by the customer.
	 *
	 * @param array $post_data The data containing job_id.
	 * @return array The response indicating the status of the operation.
	 */
	public function customerNotCall($post_data)
	{
		$completeddate = now(); // Use Carbon to get the current date and time
		$jobid = $post_data["job_id"];
		$job_detail = Job::with('translatorJobRel')->find($jobid);
		$duedate = $job_detail->due;
		$start = Carbon::createFromFormat('Y-m-d H:i:s', $duedate);
		$end = $completeddate;
		$diff = $end->diff($start);
		$interval = $diff->format('%h:%i:%s');

		$job = $job_detail;
		$job->end_at = $completeddate;
		$job->status = 'not_carried_out_customer';

		$tr = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();
		$tr->completed_at = $completeddate;
		$tr->completed_by = $tr->user_id;

		$job->save();
		$tr->save();

		$response['status'] = 'success';
		return $response;
	}


	/**
	 * Get a list of jobs based on filters and user type.
	 *
	 * @param Request $request The HTTP request object.
	 * @param int|null $limit The limit for pagination.
	 * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Database\Eloquent\Collection|array The list of jobs.
	 */
    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $allJobs = Job::query();

            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
                if (isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count()];
            }

            if (isset($requestdata['id']) && $requestdata['id'] != '') {
                if (is_array($requestdata['id']))
                    $allJobs->whereIn('id', $requestdata['id']);
                else
                    $allJobs->where('id', $requestdata['id']);
                $requestdata = array_only($requestdata, ['id']);
            }

            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('from_language_id', $requestdata['lang']);
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('status', $requestdata['status']);
            }
            if (isset($requestdata['expired_at']) && $requestdata['expired_at'] != '') {
                $allJobs->where('expired_at', '>=', $requestdata['expired_at']);
            }
            if (isset($requestdata['will_expire_at']) && $requestdata['will_expire_at'] != '') {
                $allJobs->where('will_expire_at', '>=', $requestdata['will_expire_at']);
            }
            if (isset($requestdata['customer_email']) && count($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $users = DB::table('users')->whereIn('email', $requestdata['customer_email'])->get();
                if ($users) {
                    $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
                }
            }
            if (isset($requestdata['translator_email']) && count($requestdata['translator_email'])) {
                $users = DB::table('users')->whereIn('email', $requestdata['translator_email'])->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                    $allJobs->whereIn('id', $allJobIDs);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('created_at', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }
                $allJobs->orderBy('created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('due', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }
                $allJobs->orderBy('due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('job_type', $requestdata['job_type']);
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }

            if (isset($requestdata['physical'])) {
                $allJobs->where('customer_physical_type', $requestdata['physical']);
                $allJobs->where('ignore_physical', 0);
            }

            if (isset($requestdata['phone'])) {
                $allJobs->where('customer_phone_type', $requestdata['phone']);
                if(isset($requestdata['physical']))
                $allJobs->where('ignore_physical_phone', 0);
            }

            if (isset($requestdata['flagged'])) {
                $allJobs->where('flagged', $requestdata['flagged']);
                $allJobs->where('ignore_flagged', 0);
            }

            if (isset($requestdata['distance']) && $requestdata['distance'] == 'empty') {
                $allJobs->whereDoesntHave('distance');
            }

            if(isset($requestdata['salary']) &&  $requestdata['salary'] == 'yes') {
                $allJobs->whereDoesntHave('user.salaries');
            }

            if (isset($requestdata['count']) && $requestdata['count'] == 'true') {
                $allJobs = $allJobs->count();

                return ['count' => $allJobs];
            }

            if (isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '') {
                $allJobs->whereHas('user.userMeta', function($q) use ($requestdata) {
                    $q->where('consumer_type', $requestdata['consumer_type']);
                });
            }

            if (isset($requestdata['booking_type'])) {
                if ($requestdata['booking_type'] == 'physical')
                    $allJobs->where('customer_physical_type', 'yes');
                if ($requestdata['booking_type'] == 'phone')
                    $allJobs->where('customer_phone_type', 'yes');
            }
            
            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            if ($limit == 'all')
                $allJobs = $allJobs->get();
            else
                $allJobs = $allJobs->paginate(15);

        } else {

            $allJobs = Job::query();

            if (isset($requestdata['id']) && $requestdata['id'] != '') {
                $allJobs->where('id', $requestdata['id']);
                $requestdata = array_only($requestdata, ['id']);
            }

            if ($consumer_type == 'RWS') {
                $allJobs->where('job_type', '=', 'rws');
            } else {
                $allJobs->where('job_type', '=', 'unpaid');
            }
            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function($q) {
                    $q->where('rating', '<=', '3');
                });
                if(isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count()];
            }
            
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('from_language_id', $requestdata['lang']);
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('status', $requestdata['status']);
            }
            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('job_type', $requestdata['job_type']);
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('user_id', '=', $user->id);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('created_at', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }
                $allJobs->orderBy('created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('due', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }
                $allJobs->orderBy('due', 'desc');
            }

            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            if ($limit == 'all')
                $allJobs = $allJobs->get();
            else
                $allJobs = $allJobs->paginate(15);

        }
        return $allJobs;
    }

	/**
	 * Get alerts related to jobs.
	 *
	 * @return array An array containing relevant job alerts.
	 */
    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs [$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId [] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $jobId);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    /**
	 * Get user login failures that are not ignored.
	 *
	 * @return array An array containing user login failures.
	 */
	public function userLoginFailed()
	{
		// Retrieve throttles with associated users
		$throttles = Throttles::where('ignore', 0)
			->with('user')
			->paginate(15);

		return ['throttles' => $throttles];
	}

	/**
	 * Get pending booking jobs that are not yet accepted and not ignored.
	 *
	 * @return array An array containing pending booking jobs.
	 */
    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);

        }
        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    /**
	 * Ignore the expiration status of a job.
	 *
	 * @param int $id The ID of the job to ignore expiration for.
	 * @return array An array indicating the success of the operation.
	 */
	public function ignoreExpired($id)
	{
		$job = Job::findOrFail($id); // Use findOrFail to handle not found case
		$job->ignore_expired = 1;
		$job->save();

		return ['status' => 'success', 'message' => 'Changes saved'];
	}

	/**
	 * Ignore the expiring status of a job.
	 *
	 * @param int $id The ID of the job to ignore expiring for.
	 * @return array An array indicating the success of the operation.
	 */
	public function ignoreExpiring($id)
	{
		$job = Job::findOrFail($id); // Use findOrFail to handle not found case
		$job->ignore = 1;
		$job->save();

		return ['status' => 'success', 'message' => 'Changes saved'];
	}

	/**
	 * Ignore the throttling status.
	 *
	 * @param int $id The ID of the throttle record to ignore.
	 * @return array An array indicating the success of the operation.
	 */
	public function ignoreThrottle($id)
	{
		$throttle = Throttles::findOrFail($id); // Use findOrFail to handle not found case
		$throttle->ignore = 1;
		$throttle->save();

		return ['status' => 'success', 'message' => 'Changes saved'];
	}


    /**
	 * Reopen a job.
	 *
	 * @param array $request The request data containing jobid and userid.
	 * @return array An array indicating the result of the operation.
	 */
	public function reopenJob($request)
	{
		$jobid = $request['jobid'];
		$userid = $request['userid'];

		// Get the original job
		$originalJob = Job::find($jobid);
		$originalJobData = $originalJob->toArray();

		// Create data for new Translator entry
		$data = [
			'created_at' => now(),
			'will_expire_at' => TeHelper::willExpireAt($originalJobData['due'], now()),
			'updated_at' => now(),
			'user_id' => $userid,
			'job_id' => $jobid,
			'cancel_at' => now(),
		];

		// Create data for reopening the job
		$dataReopen = [
			'status' => 'pending',
			'created_at' => now(),
			'will_expire_at' => TeHelper::willExpireAt($originalJobData['due'], now()),
		];

		// Reopen the job
		if ($originalJobData['status'] != 'timedout') {
			Job::where('id', $jobid)->update($dataReopen);
			$new_jobid = $jobid;
		} else {
			// If the job was timed out, create a new job entry
			$newJobData = array_merge($originalJobData, [
				'status' => 'pending',
				'created_at' => now(),
				'updated_at' => now(),
				'will_expire_at' => TeHelper::willExpireAt($originalJobData['due'], now()),
				'cust_16_hour_email' => 0,
				'cust_48_hour_email' => 0,
				'admin_comments' => 'This booking is a reopening of booking #' . $jobid,
			]);

			$newJob = Job::create($newJobData);
			$new_jobid = $newJob->id;
		}

		// Update the cancel_at field for existing Translator entries
		Translator::where('job_id', $jobid)->whereNull('cancel_at')->update(['cancel_at' => $data['cancel_at']]);
		// Create a new Translator entry
		$translatorEntry = Translator::create($data);

		if ($new_jobid) {
			// Send notification for job cancellation to admin
			$this->sendNotificationByAdminCancelJob($new_jobid);

			return ['message' => 'Job reopened successfully'];
		} else {
			return ['message' => 'Failed to reopen job'];
		}
	}


   /**
	 * Convert the number of minutes to an hour and minute variant.
	 *
	 * @param int $time The time in minutes.
	 * @param string $format The format to use for the output.
	 * @return string The formatted time string.
	 */
	private function convertToHoursMins($time, $format = '%02dh %02dmin')
	{
		if ($time < 60) {
			return $time . 'min';
		} elseif ($time === 60) {
			return '1h';
		}

		$hours = floor($time / 60);
		$minutes = ($time % 60);

		return sprintf($format, $hours, $minutes);
	}


}