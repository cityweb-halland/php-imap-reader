<?php

namespace benhall14\phpImapReader;

use Exception;
use IMAP\Connection;

/**
 * IMAP Reader Class.
 *
 * This class is used to fetch emails based on the parameter options set. You can set useful filtering flags such as ALL, ANSWERED, DELETED, FLAGGED, NEW, OLD, RECENT, SEEN, UNANSWERED, UNDELETED, UNGLAGGED, UNKEYWORD, UNSEEN. You can match emails by BCC, BEFORE DATE, CC, FROM, KEYWORD, ON DATE, SINCE DATE, TO. You can also search for text strings in email TEXT, SUBJECT and BODY.
 *
 * @category  Protocols
 * @package   Protocols
 * @author    Benjamin Hall <ben@conobe.co.uk>
 * @copyright 2022 Copyright (c) Benjamin Hall
 * @license   MIT https://github.com/benhall14/php-imap-reader
 * @link      https://conobe.co.uk/projects/php-imap-reader/
 */
class Reader {

	/**
	 * The IMAP host name.
	 */
	public string $hostname;

	/**
	 * The IMAP username.
	 */
	public string $user_name;

	/**
	 * The IMAP password.
	 */
	public string $password;

	public string $encoding;

	public bool $mark_as_read;

	/**
	 * Save attachment status - should we save attachments.
	 */
	public bool $save_attachments;

	/**
	 * Retry status
	 */
	public int $retry;

	/**
	 * The IMAP handler.
	 */
	public ?Connection $imap = null;

	/**
	 * The index of email ids.
	 */
	public array $email_index = [];

	/**
	 * The array of previously fetched emails
	 *
	 * @var array|Email[]
	 */
	public array $emails = [];

	/**
	 * Modes - such as NEW or UNSEEN.
	 */
	public array $modes = [];

	/**
	 * The id of a specific id.
	 */
	public int $id = 0;

	/**
	 * Limit the number of emails fetch. Can be used with page for pagination.
	 */
	public int $limit = 0;

	/**
	 * Page number. Can be used with limit for pagination.
	 */
	public int $page = 0;

	/**
	 * The email offset.
	 */
	public int $offset = 0;

	/**
	 * The sorting order direction.
	 */
	public string $order = 'DESC';

	/**
	 * The mailbox name.
	 */
	public string $mailbox = 'INBOX';

	public ?string $attachment_dir = null;

	/**
	 * Sets the IMAP Reader
	 *
	 * @param string $hostname The IMAP host name.
	 * @param string $user_name The IMAP username.
	 * @param string $password The IMAP password.
	 * @param mixed $attachment_dir The directory path to store attachments or false to turn off saving attachments.
	 * @param bool $mark_as_read Whether we should mark as read.
	 * @param string $encoding
	 *
	 * @return bool
	 * @throws Exception
	 */
	public function __construct(
		string $hostname,
		string $user_name,
		string $password,
		?string $attachment_dir = null,
		bool $mark_as_read = true,
		string $encoding = 'UTF-8'
	) {
		$this->hostname = $hostname;

		$this->user_name = $user_name;

		$this->password = $password;

		$this->encoding = $encoding;

		$this->retry = 0;

		$this->mark_as_read = $mark_as_read;

		$this->save_attachments = false;

		if($attachment_dir) {
			if(!is_dir($attachment_dir)) {
				throw new Exception(
					'ERROR: Directory "'.$attachment_dir.'" could not be found.'
				);
			}

			if(!is_writable($attachment_dir)) {
				throw new Exception(
					'ERROR: Directory "'.$attachment_dir.'" is not writable.'
				);
			}

			$this->save_attachments = true;
			$this->attachment_dir = $attachment_dir;
		}

		return true;
	}

	/**
	 * Returns true/false based on if $imap is an imap resource.
	 *
	 * @param mixed $imap
	 *
	 * @return bool
	 */
	public function isImapResource(mixed $imap): bool {
		return $imap instanceof Connection;
	}

	/**
	 * Fetch the active IMAP stream. If no stream is active, try a connection.
	 *
	 * @param bool $reconnect Whether to reconnect connection.
	 *
	 * @return Connection
	 */
	public function stream(bool $reconnect = false): Connection {
		if ($this->imap && (!$this->isImapResource($this->imap) || !imap_ping($this->imap))) {
			$this->close();

			$this->imap = null;
		}

		if(!$this->imap || $reconnect) {
			$this->imap = $this->connect();
		}

		return $this->imap;
	}

	/**
	 * Connect to an IMAP stream.
	 *
	 * @return Connection
	 * @throws Exception
	 */
	public function connect(): Connection {
		$stream = imap_open(
			$this->hostname.$this->mailbox,
			$this->user_name,
			$this->password,
			false,
			$this->retry
		);

		if(!$stream) {
			$last_error = imap_last_error();

			imap_errors();

			throw new Exception('ERROR: Could Not Connect ('.$last_error.')');
		}

		return $stream;
	}

	/**
	 * Close the current IMAP stream.
	 *
	 * @return Reader
	 */
	public function close(): Reader {
		if($this->isImapResource($this->imap)) {
			imap_close($this->imap, CL_EXPUNGE);
		}

		return $this;
	}

	/**
	 * Resets the reader to be able to connect to another folder.
	 *
	 * @return Reader
	 */
	public function reset(): Reader {
		$this->close();

		$this->emails = [];

		return $this;
	}

	/**
	 * Close connection on destruct.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * The number of emails found.
	 *
	 * @return int
	 */
	public function count(): int {
		return imap_num_msg($this->stream());
	}

	/**
	 * Get the last error.
	 *
	 * @return string The error message.
	 */
	public function getError(): string {
		return imap_last_error();
	}

    /**
     * Alias for doesMailboxExist - Returns true/false based on if the specified folder/mailbox exists on the IMAP stream.
     *
     * @param string $folder_name
     *
     * @return bool
     */
    public function doesFolderExist($folder_name = null): bool
    {
        return $this->doesMailboxExist($folder_name);
    }

    /**
     * Returns true/false based on if the specified folder/mailbox exists on the IMAP stream.
     *
     * @param string $mailbox
     *
     * @return bool
     */
    public function doesMailboxExist($mailbox): bool
    {
        if (!$mailbox) {
            return false;
        }

        $mailboxes = imap_list($this->stream(), $this->hostname, "*");

        if (!in_array($this->hostname . $mailbox, $mailboxes)) {
            return false;
        }

        return true;
    }

    /**
     * Create a new folder/mailbox on the IMAP stream.
     *
     * @param string $folder_name
     *
     * @return bool
     */
    public function makeFolder($folder_name = null): bool
    {
        if (!$folder_name) {
            return false;
        }

        if ($this->doesFolderExist($folder_name)) {
            return false;
        }

        return imap_createmailbox($this->stream(), imap_utf7_encode($this->hostname . $folder_name));
    }

    /**
     * Alias for makeFolder. Creates a new folder/mailbox on the IMAP stream.
     *
     * @param string $folder_name
     *
     * @return bool
     */
    public function createFolder($folder_name): bool
    {
        return $this->makeFolder($folder_name);
    }

    /**
     * Alias for makeFolder. Create a new folder/mailbox on the IMAP stream.
     *
     * @param string $mailbox
     *
     * @return boolean
     */
    public function createMailbox($mailbox = null): bool
    {
        return $this->makeFolder($mailbox);
    }

    /**
     * Alias for makeFolder. Create a new folder/mailbox on the IMAP stream.
     *
     * @param string $mailbox
     *
     * @return boolean
     */
    public function makeMailbox($mailbox = null): bool
    {
        return $this->makeFolder($mailbox);
    }

    /**
     * Expunge all emails that are marked for deletion on the connected inbox.
     *
     * @return boolean
     */
    public function expunge(): bool
    {
        return imap_expunge($this->stream());
    }
	/**
	 * Delete an email by given email id.
	 *
	 * @param int $email_id The id of the email to delete.
	 *
	 * @return bool
	 */
	public function deleteEmail(int $email_id): bool {
		return imap_delete($this->stream(), $email_id, FT_UID);
	}

	/**
	 * Mark an email as read by given email id.
	 *
	 * @param int $email_id The id of the email to mark as read.
	 *
	 * @return bool
	 */
	public function markAsRead(int $email_id): bool {
		return imap_setflag_full($this->stream(), $email_id, '\\Seen', ST_UID);
	}

	/**
	 * Move mail to specific folder
	 *
	 * @param int $email_id The id of the email to move.
	 * @param string $folder Destination folder
	 * @return bool       The result of the action.
	 */
	public function moveEmailToFolder(int $email_id, string $folder): bool {
		if($this->mailbox == $folder) {
			return false;
		}

        return imap_mail_move($this->stream(), (string) $email_id, $folder, CP_UID);
    }

	/**
	 * Get the list of emails from the last get call.
	 *
	 * @return array An array of returned emails.
	 */
	public function emails(): array {
		return $this->emails;
	}

	/**
	 * Get the first email from the list of returned emails. This is a shortcut for $this->emails[0];
	 *
	 * @return Email|null The email.
	 */
	public function email(): ?Email {
		return $this->emails && isset($this->emails[0])
			? $this->emails[0] : null;
	}

	/**
	 * Get the email based on the given id.
	 *
	 * @param int $id The Email Id.
	 *
	 * @return Reader
	 */
	public function id(int $id): Reader {
		$this->id = $id;

		return $this;
	}

	/**
	 * Add 'all' to the mode selection.
	 * This allows for matching 'all' emails.
	 *
	 * @return Reader
	 */
	public function all(): Reader {
		$this->modes[] = 'ALL';

		return $this;
	}

	/**
	 * Add 'flagged' to the mode selection.
	 * This allows for matching 'flagged' emails.
	 *
	 * @return Reader
	 */
	public function flagged(): Reader {
		$this->modes[] = 'FLAGGED';

		return $this;
	}

	/**
	 * Add 'unanswered' to the mode selection.
	 * This allows for matching 'unanswered' emails.
	 *
	 * @return Reader
	 */
	public function unanswered(): Reader {
		$this->modes[] = 'UNANSWERED';

		return $this;
	}

	/**
	 * Add 'deleted' to the mode selection.
	 * This allows for matching 'deleted' emails.
	 *
	 * @return Reader
	 */
	public function deleted(): Reader {
		$this->modes[] = 'DELETED';

		return $this;
	}

	/**
	 * Add 'unseen' to the mode selection.
	 * This allows for matching 'unseen' emails.
	 *
	 * @return Reader
	 */
	public function unseen(): Reader {
		$this->modes[] = 'UNSEEN';

		return $this;
	}

	/**
	 * An alias of unseen. See unseen().
	 *
	 * @return Reader
	 */
	public function unread(): Reader {
		return $this->unseen();
	}

	/**
	 * Add 'from' to the mode selection.
	 * This allows for matching emails 'from' the given email address.
	 *
	 * @param string $from The sender email address.
	 *
	 * @return Reader
	 */
	public function from(string $from): Reader {
		$this->modes[] = 'FROM "'.$from.'"';

		return $this;
	}

	/**
	 * Add the body search to the mode selection.
	 * This allows for searching for a string within a body
	 *
	 * @param string $string The search keywords.
	 *
	 * @return Reader
	 */
	public function searchBody(string $string): Reader {
		if($string) {
			$this->modes[] = 'BODY "'.$string.'"';
		}

		return $this;
	}

	/**
	 * Add the subject search to the mode selection.
	 * This allows for searching for a string within a subject line.
	 *
	 * @param string $string The string to search for.
	 *
	 * @return Reader
	 */
	public function searchSubject(string $string): Reader {
		if($string) {
			$this->modes[] = 'SUBJECT "'.$string.'"';
		}

		return $this;
	}

	/**
	 * Add the recent flag to the mode selection.
	 * This allows for matching recent emails.
	 *
	 * @return Reader
	 */
	public function recent(): Reader {
		$this->modes[] = 'RECENT';

		return $this;
	}

	/**
	 * Add the unflagged flag to the mode selection.
	 * This allows for matching unflagged emails.
	 *
	 * @return Reader
	 */
	public function unflagged(): Reader {
		$this->modes[] = 'UNFLAGGED';

		return $this;
	}

	/**
	 * Add the seen flag to the mode selection.
	 * This allows for matching seen emails.
	 *
	 * @return Reader
	 */
	public function seen(): Reader {
		$this->modes[] = 'SEEN';

		return $this;
	}

	/**
	 * Alias of seen(). See Seen().
	 *
	 * @return void
	 */
	public function read(): void {
		$this->seen();
	}

	/**
	 * Add the new flag to the mode selection. This allows for matching new emails.
	 *
	 * @return Reader
	 */
	public function newMessages(): Reader {
		$this->modes[] = 'NEW';

		return $this;
	}

	/**
	 * Add the old flag to the mode selection.
	 * This allows for matching old emails.
	 *
	 * @return Reader
	 */
	public function oldMessages(): Reader {
		$this->modes[] = 'OLD';

		return $this;
	}

	/**
	 * Add the keyword flag to the mode selection.
	 * This allows for matching emails with the given keyword.
	 *
	 * @param string $keyword The keyword to search for.
	 *
	 * @return Reader
	 */
	public function keyword(string $keyword): Reader {
		$this->modes[] = 'KEYWORD "'.$keyword.'"';

		return $this;
	}

	/**
	 * Add the unkeyword flag to the mode selection.
	 * This allows for matching emails without the given keyword.
	 *
	 * @param string $keyword The keyword to avoid.
	 *
	 * @return Reader
	 */
	public function unkeyword(string $keyword): Reader {
		$this->modes[] = 'UNKEYWORD "'.$keyword.'"';

		return $this;
	}

	/**
	 * Add the before date flag to the mode selection.
	 * This allows for matching emails received before the given date.
	 *
	 * @param string $date The date to match.
	 *
	 * @return Reader
	 */
	public function beforeDate(string $date): Reader {
		$date = date('d-M-Y', strtotime($date));

		$this->modes[] = 'BEFORE "'.$date.'"';

		return $this;
	}

	/**
	 * Add the since date flag to the mode selection.
	 * This allows for matching emails received since the given date.
	 *
	 * @param string $date The date to match.
	 *
	 * @return Reader
	 */
	public function sinceDate(string $date): Reader {
		$date = date('d-M-Y', strtotime($date));

		$this->modes[] = 'SINCE "'.$date.'"';

		return $this;
	}

	/**
	 * Add the sent to flag to the mode selection.
	 * This allows for matching emails sent to the given email address string.
	 *
	 * @param string $to The email address to match.
	 *
	 * @return Reader
	 */
	public function sentTo(string $to): Reader {
		$this->modes[] = 'TO "'.$to.'"';

		return $this;
	}

	/**
	 * Add the BCC flag to the mode selection.
	 * This allows for matching emails with the string present in the BCC field.
	 *
	 * @param string $to The email address to match.
	 *
	 * @return Reader
	 */
	public function searchBCC(string $to): Reader {
		$this->modes[] = 'BCC "'.$to.'"';

		return $this;
	}

	/**
	 * Add the CC flag to the mode selection.
	 * This allows for matching emails with the string present in the CC field.
	 *
	 * @param string $to The email address to match.
	 *
	 * @return Reader
	 */
	public function searchCC(string $to): Reader {
		$this->modes[] = 'CC "'.$to.'"';

		return $this;
	}

	/**
	 * Add the on date flag to the mode selection.
	 * This allows for matching emails received on the given date.
	 *
	 * @param string $date The date to match.
	 *
	 * @return Reader
	 */
	public function onDate(string $date): Reader {
		$date = date('d-M-Y', strtotime($date));

		$this->modes[] = 'ON "'.$date.'"';

		return $this;
	}

	/**
	 * Add the text flag to the mode selection.
	 * This allows for matching emails with the given text string.
	 *
	 * @param string $string The string to search for.
	 *
	 * @return Reader
	 */
	public function searchText(string $string): Reader {
		$this->modes[] = 'TEXT "'.$string.'"';

		return $this;
	}

	/**
	 * Set the limit.
	 * This can be used with page() for pagination of emails.
	 *
	 * @param int $limit The total number of emails to return.
	 *
	 * @return Reader
	 */
	public function limit(int $limit): Reader {
		$this->limit = $limit;

		return $this;
	}

	/**
	 * Set the page number.
	 * This is used with limit() for pagination of emails.
	 *
	 * @param int $page The page number.
	 *
	 * @return Reader
	 */
	public function page(int $page): Reader {
		$this->page = $page;

		$this->offset = ($page - 1) * $this->limit;

		return $this;
	}

	/**
	 * Set the email fetching order to ASCending.
	 *
	 * @return Reader
	 */
	public function orderASC(): Reader {
		$this->order = 'ASC';

		return $this;
	}

	/**
	 * Set the email fetching order to DESCending.
	 *
	 * @return Reader
	 */
	public function orderDESC(): Reader {
		$this->order = 'DESC';

		return $this;
	}

	/**
	 * Sets the folder to retrieve emails from. Alias for mailbox().
	 *
	 * @param string $folder The name of the folder. IE. INBOX.
	 *
	 * @return Reader
	 */
	public function folder(string $folder): Reader {
		return $this->mailbox($folder);
	}

	/**
	 * Sets the mailbox to retrieve emails from.
	 *
	 * @param string $mailbox The name of the mailbox, IE. INBOX.
	 *
	 * @return Reader
	 */
	public function mailbox(string $mailbox): Reader {
		$this->mailbox = $mailbox;

		return $this;
	}

	/**
	 * Get a formatted list of selected modes for imap_search.
	 *
	 * @return string
	 */
	public function modes(): string {
		if(!$this->modes) {
			$this->modes[] = 'ALL';
		}

		return implode(' ', $this->modes);
	}

	/**
	 * Fetch emails based on the previously set parameters.
	 *
	 * @return array|Email[]
	 * @throws Exception
	 */
	public function get(): array {
		if(!$this->connect()) {
			throw new Exception('ERROR: Could not connect.');
		}

		if($this->id) {
			$this->emails = array();

			$this->emails[] = $this->getEmail($this->id);

			return $this->emails;
		}

		$this->emails = array();

		$this->email_index = imap_search(
			$this->stream(),
			$this->modes(),
			false,
			$this->encoding
		);

		if(!$this->limit && $this->email_index) {
			$this->limit = count($this->email_index);
		}

		if($this->email_index) {
			if($this->order == 'DESC') {
				rsort($this->email_index);
			} else {
				sort($this->email_index);
			}

			if($this->offset) {
				$this->email_index = array_slice(
					$this->email_index,
					$this->offset,
					$this->limit
				);
			}

			$this->emails = array();

			foreach($this->email_index as $id) {
				$this->emails[] = $this->getEmailByMessageSequence($id);

				if($this->mark_as_read) {
					$this->markAsRead($id);
				}
			}
		}

		return $this->emails;
	}

	/**
	 * Fetches an email by its UID.
	 *
	 * @param int $uid UID Number
	 *
	 * @return Email|null
	 */
	public function getEmailByUID(int $uid): ?Email {
		return $this->getEmail($uid);
	}

	/**
	 * Fetches an email by its message sequence id
	 *
	 * @param int $id ID
	 *
	 * @return Email|null
	 */
	public function getEmailByMessageSequence(int $id): ?Email {
		$uid = imap_uid($this->stream(), $id);

		return $this->getEmail($uid);
	}

	/**
	 * Fetch an email by id.
	 *
	 * @param int $uid The message UID.
	 *
	 * @return Email|null
	 */
	public function getEmail(int $uid): ?Email {
		$email = new Email();

		// imap_headerinfo doesn't work with the uid, so we use imap_fetchbody instead.
		//$header = imap_headerinfo($this->stream(), $uid);

		$options = ($this->mark_as_read) ? FT_UID : FT_UID | FT_PEEK;

		$header_from_body = imap_fetchbody($this->stream(), $uid, '0', $options);

		$header = imap_rfc822_parse_headers($header_from_body);

		// if(!$header) return null;

		$email->setId($uid);

		$header->subject = isset($header->subject)
			? $this->decodeMimeHeader($header->subject)
			: false;

		$email->setSubject($header->subject);

		$email->setDate($header->date ?? null);

		if(isset($header->to)) {
			foreach($header->to as $to) {
				$to_name = isset($to->personal)
					? $this->decodeMimeHeader($to->personal)
					: false;
				$email->addTo($to->mailbox, $to->host, $to_name);
			}
		}

		if(isset($header->from)) {
			$from_name = isset($header->from[0]->personal)
				? $this->decodeMimeHeader($header->from[0]->personal)
				: false;
			$email->setFrom(
				$header->from[0]->mailbox,
				$header->from[0]->host,
				$from_name
			);
		}

		if(isset($header->reply_to)) {
			foreach($header->reply_to as $reply_to) {
				$reply_to_name = isset($reply_to->personal)
					? $this->decodeMimeHeader($reply_to->personal)
					: false;
				$email->addReplyTo(
					$reply_to->mailbox,
					$reply_to->host,
					$reply_to_name
				);
			}
		}

		if(isset($header->cc)) {
			foreach($header->cc as $cc) {
				$cc_name = isset($cc->personal)
					? $this->decodeMimeHeader($cc->personal)
					: false;
				$email->addCC($cc->mailbox, $cc->host, $cc_name);
			}
		}

        $email->setRawBody(imap_fetchbody($this->stream(), $uid, '', $options));
        
        $body = imap_fetchstructure($this->stream(), $uid, FT_UID);

		if(isset($body->parts) && count($body->parts)) {
			foreach($body->parts as $part_number => $part) {
				$this->decodePart($email, $part, $part_number + 1);
			}
		} else {
			$this->decodePart($email, $body);
		}

		$msgno = imap_msgno($this->stream(), $uid);

		$email->setMsgno($msgno);

		$header = imap_headerinfo($this->imap, $msgno, 20, 20);

		$email->setSize($header->Size ?? 0);

		$email->setUdate($header->udate ?? null);

		$recent = in_array($header->Recent ?? null, ['R', 'N']);
		$email->setRecent($recent);

		$unseen = ($header->Unseen ?? null) === 'U';
		$email->setUnseen($unseen);

		$flagged = ($header->Flagged ?? null) === 'F';
		$email->setFlagged($flagged);

		$answered = ($header->Answered ?? null) === 'A';
		$email->setAnswered($answered);

		$deleted = ($header->Deleted ?? null) === 'D';
		$email->setDeleted($deleted);

		$draft = ($header->Draft ?? null) === 'X';
		$email->setDraft($draft);

		$headers = imap_fetchheader($this->stream(), $email->msgno());

		if($headers) {

			$headers_array = explode("\n", imap_fetchheader($this->stream(), $email->msgno()));

			foreach($headers_array as $header) {
				if(str_contains($header, "X-")) {
					$email->addCustomHeader($header);
				}
			}
		}

		return $email;
	}

	/**
	 * Decode an email part.
	 *
	 * @param Email $email The email object to update.
	 * @param object $part The part data to decode.
	 * @param bool $part_number The part number.
	 *
	 * @return string
	 */
	public function decodePart(Email $email, object $part, bool $part_number = false): string {
		$options = ($this->mark_as_read) ? FT_UID : FT_UID | FT_PEEK;

		if($part_number) {
			$data = imap_fetchbody(
				$this->stream(),
				$email->id(),
				$part_number,
				$options
			);
		} else {
			$data = imap_body($this->stream(), $email->id(), $options);
		}

		switch($part->encoding) {
			case 1:
				$data = imap_utf8($data);
				break;

			case 2:
				$data = imap_binary($data);
				break;

			case 3:
				$data = imap_base64($data);
				break;

			case 4:
				$data = quoted_printable_decode($data);
				break;
		}

		$params = array();
		if(isset($part->parameters)) {
			foreach($part->parameters as $param) {
				$params[strtolower($param->attribute)] = $param->value;
			}
		}

		if(isset($part->dparameters)) {
			foreach($part->dparameters as $param) {
				$params[strtolower($param->attribute)] = $param->value;
			}
		}

		// is this part an attachment
		$attachment_id = false;
		$is_attachment = false;

		if(
			isset($part->disposition)
			&& in_array(strtolower($part->disposition), array('attachment', 'inline'))
			&& $part->subtype != 'PLAIN'
		) {
			$is_attachment = true;
			$attachment_type = strtolower($part->disposition);

			if($attachment_type == 'inline') {
				$is_inline_attachment = true;
				$attachment_id = isset($part->id) ? trim($part->id, " <>") : false;
			} else {
				$is_inline_attachment = false;
				$attachment_id = rand();
			}
		}

		// if there is an attachment
		if($is_attachment) {
			$file_name = false;

			if(isset($params['filename'])) {
				$file_name = $params['filename'];
			} else if(isset($params['name'])) {
				$file_name = $params['name'];
			}

			if($file_name) {
				$file_name = $attachment_id.'-'.$file_name;

				$attachment = new EmailAttachment();
				$attachment->setId($attachment_id);
				$attachment->setName($file_name);

				if($is_inline_attachment ?? false) {
					$attachment->setType('inline');
				} else {
					$attachment->setType('attachment');
				}

				if($this->save_attachments) {
					$attachment->setFilePath(
						$this->attachment_dir.DIRECTORY_SEPARATOR.$attachment->name()
					);

					if($this->attachment_dir && $attachment->filePath()) {
						if(!file_exists($attachment->filePath())) {
							file_put_contents($attachment->filePath(), $data);
						}
					}
				}/* else {
					//$attachment->setAttachmentContent($data);
				}*/

				$email->addAttachment($attachment);
			}
		} else {
			// if the charset is set, convert to our encoding UTF-8
			if(!empty($params['charset'])) {
				$data = $this->convertEncoding($data, $params['charset']);
			}

			// part->type = 0 is TEXT or TYPETEXT
			if(isset($part->type)) {
				if($part->type == 0) {
					// subpart is either plain text or html version
					if(strtoupper($part->subtype) == 'PLAIN') {
						$email->setPlain($data);
					} else {
						$email->setHTML($data);
					}

					// part->type = 2 is MESSAGE
				} else if($part->type == 2) {
					$email->setPlain($data);
				}
			}
		}

		// rerun for additional parts
		if(!empty($part->parts)) {
			foreach($part->parts as $subpart_number => $subpart) {
				if($part->type == 2 && $part->subtype == 'RFC822') {
					$this->decodePart($email, $subpart, $part_number);
				} else {
					$this->decodePart($email, $subpart, $part_number.'.'.($subpart_number + 1));
				}
			}
		}

		return trim($data);
	}

	/**
	 * Decode Mime Header.
	 *
	 * @param string $encoded_header The encoded header string.
	 *
	 * @return string
	 */
	public function decodeMimeHeader(string $encoded_header): string {
		$decoded_header = '';

		$elements = imap_mime_header_decode($encoded_header);

		for($i = 0; $i < count($elements); $i++) {
			if($elements[$i]->charset == 'default') {
				$elements[$i]->charset = 'iso-8859-1';
			}

			$decoded_header .= $this->convertEncoding($elements[$i]->text, $elements[$i]->charset);
		}

		return $decoded_header;
	}

	/**
	 * Convert a string encoding to the encoding set.
	 *
	 * @param string $string The string to re-encode.
	 * @param string $current_encoding_type The encoding type of the original string.
	 *
	 * @return string
	 */
	public function convertEncoding(string $string, string $current_encoding_type): string {
		if(!$string) {
			return $string;
		}

		if($current_encoding_type == $this->encoding) {
			return $string;
		}

		$converted_string = extension_loaded('mbstring')
			? mb_convert_encoding($string, $this->encoding, $current_encoding_type)
			: iconv($current_encoding_type, $this->encoding.'//IGNORE', $string);

		return $converted_string ?: $string;
	}
}
