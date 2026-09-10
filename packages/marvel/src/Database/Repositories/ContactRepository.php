<?php

namespace Marvel\Database\Repositories;

use Marvel\Database\Models\Contact;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use App\Events\ContactMessageReceived;

class ContactRepository extends BaseRepository
{
    protected $fieldSearchable = [
        'email' => 'like',
        'subject' => 'like',
        'message' => 'like',
    ];

    protected $dataArray = [
        'name',
        'email',
        'subject',
        'message',
    ];

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }

    /**
     * Configure the Model
     **/
    public function model()
    {
        return Contact::class;
    }

    public function saveContact($data)
    {
        $contact = $this->create($data->only($this->dataArray));
        ContactMessageReceived::dispatch($contact);

        return $contact;
    }

    public function markAsRead($id)
    {
        $contact = $this->findOrFail($id);
        $contact->is_read = true;
        $contact->save();

        return $contact;
    }

    public function ReplayContact($data, $id)
    {
        $contact = $this->findOrFail($id);

        // Logic to send email to the contact's email address with the provided data
        $replayContent = $this->create([
            'name' => $contact->name,
            'email' => $contact->email,
            'subject' => $data->subject,
            'message' => $data->message,
            'is_read' => true,
            'is_replay' => true,
        ]);
        // You can use Laravel's Mail facade or any other email service to send the email

        return $replayContent; // Return the created replay content if the email was sent successfully
    }

    public function deleteAllContacts()
    {
        return Contact::query()->update(['deleted_at' => now()]);
    }
    public function deleteAllReadContacts()
    {
        return Contact::query()->where('is_read', true)->update(['deleted_at' => now()]);
    }
}
