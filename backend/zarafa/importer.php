<?php
/***********************************************
* File      :   importer.php
* Project   :   Z-Push
* Descr     :   This is a generic class that is
*               used by both the proxy importer
*               (for outgoing messages) and our
*               local importer (for incoming
*               messages). Basically all shared
*               conversion data for converting
*               to and from MAPI objects is in here.
*
* Created   :   14.02.2011
*
* Copyright 2007 - 2011 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/



/**
 * This is our local importer. Tt receives data from the PDA, for contents and hierarchy changes.
 * It must therefore receive the incoming data and convert it into MAPI objects, and then send
 * them to the ICS importer to do the actual writing of the object.
 * The creation of folders is fairly trivial, because folders that are created on
 * the PDA are always e-mail folders.
 */

class ImportChangesICS implements IImportChanges {
    private $folderid;
    private $store;
    private $session;
    private $flags;
    private $statestream;
    private $importer;
    private $memChanges;
    private $mapiprovider;
    private $conflictsLoaded;
    private $conflictsContentParameters;
    private $conflictsState;

    /**
     * Constructor
     *
     * @param mapisession       $session
     * @param mapistore         $store
     * @param string            $folderid (opt)
     *
     * @access public
     * @throws StatusException
     */
    public function ImportChangesICS($session, $store, $folderid = false) {
        $this->session = $session;
        $this->store = $store;
        $this->folderid = $folderid;
        $this->conflictsLoaded = false;

        if ($folderid) {
            $entryid = mapi_msgstore_entryidfromsourcekey($store, $folderid);
        }
        else {
            $storeprops = mapi_getprops($store, array(PR_IPM_SUBTREE_ENTRYID));
            $entryid = $storeprops[PR_IPM_SUBTREE_ENTRYID];
        }

        $folder = false;
        if ($entryid)
            $folder = mapi_msgstore_openentry($store, $entryid);

        if(!$folder) {
            $this->importer = false;

            // We throw an general error SYNC_FSSTATUS_CODEUNKNOWN (12) which is also SYNC_STATUS_FOLDERHIERARCHYCHANGED (12)
            // if this happened while doing content sync, the mobile will try to resync the folderhierarchy
            throw new StatusException(sprintf("ImportChangesICS('%s','%s','%s'): Error, unable to open folder: 0x%X", $session, $store, Utils::PrintAsString($folderid), mapi_last_hresult()), SYNC_FSSTATUS_CODEUNKNOWN);
        }

        $this->mapiprovider = new MAPIProvider($this->session, $this->store);

        if ($folderid)
            $this->importer = mapi_openproperty($folder, PR_COLLECTOR, IID_IExchangeImportContentsChanges, 0 , 0);
        else
            $this->importer = mapi_openproperty($folder, PR_COLLECTOR, IID_IExchangeImportHierarchyChanges, 0 , 0);
    }

    /**
     * Initializes the importer
     *
     * @param string        $state
     * @param int           $flags
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function Config($state, $flags = 0) {
        $this->flags = $flags;

        // this should never happen
        if ($this->importer === false)
            throw new StatusException("ImportChangesICS->Config(): Error, importer not available", SYNC_FSSTATUS_CODEUNKNOWN, null, LOGLEVEL_ERROR);

        // Put the state information in a stream that can be used by ICS
        $stream = mapi_stream_create();
        if(strlen($state) == 0)
            $state = hex2bin("0000000000000000");

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesICS->Config(): initializing importer with state: 0x%s", bin2hex($state)));

        mapi_stream_write($stream, $state);
        $this->statestream = $stream;

        if ($this->folderid !== false) {
            // possible conflicting messages will be cached here
            $this->memChanges = new ChangesMemoryWrapper();
            $stat = mapi_importcontentschanges_config($this->importer, $stream, $flags);
        }
        else
            $stat = mapi_importhierarchychanges_config($this->importer, $stream, $flags);

        if (!$stat)
            throw new StatusException(sprintf("ImportChangesICS->Config(): Error, mapi_import_*_changes_config() failed: 0x%X", mapi_last_hresult()), SYNC_FSSTATUS_CODEUNKNOWN, null, LOGLEVEL_WARN);
        return $stat;
    }

    /**
     * Reads state from the Importer
     *
     * @access public
     * @return string
     * @throws StatusException
     */
    public function GetState() {
        $error = false;
        if(!isset($this->statestream) || $this->importer === false)
            $error = true;

        if ($error === false && $this->folderid !== false && function_exists("mapi_importcontentschanges_updatestate"))
            if(mapi_importcontentschanges_updatestate($this->importer, $this->statestream) != true)
                $error = true;

        if ($error == true)
            throw new StatusException(sprintf("ImportChangesICS->GetState(): Error, state not available or unable to update: 0x%X", mapi_last_hresult()), (($this->folderid)?SYNC_STATUS_FOLDERHIERARCHYCHANGED:SYNC_FSSTATUS_CODEUNKNOWN), null, LOGLEVEL_WARN);

        mapi_stream_seek($this->statestream, 0, STREAM_SEEK_SET);

        $state = "";
        while(true) {
            $data = mapi_stream_read($this->statestream, 4096);
            if(strlen($data))
                $state .= $data;
            else
                break;
        }

        return $state;
    }

    /**----------------------------------------------------------------------------------------------------------
     * Methods for ContentsExporter
     */

    /**
     * Loads objects which are expected to be exported with the state
     * Before importing/saving the actual message from the mobile, a conflict detection should be done
     *
     * @param ContentParameters         $contentparameters         class of objects
     * @param string                    $state
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function LoadConflicts($contentparameters, $state) {
        if (!isset($this->session) || !isset($this->store) || !isset($this->folderid))
            throw new StatusException("ImportChangesICS->LoadConflicts(): Error, can not load changes for conflict detection. Session, store or folder information not available", SYNC_STATUS_SERVERERROR);

        // save data to load changes later if necessary
        $this->conflictsLoaded = false;
        $this->conflictsContentParameters = $contentparameters;
        $this->conflictsState = $state;

        ZLog::Write(LOGLEVEL_DEBUG, "ImportChangesICS->LoadConflicts(): will be loaded later if necessary");
        return true;
    }

    /**
     * Potential conflicts are only loaded when really necessary,
     * e.g. on ADD or MODIFY
     *
     * @access private
     * @return
     */
    private function lazyLoadConflicts() {
        if (!isset($this->session) || !isset($this->store) || !isset($this->folderid) ||
            !isset($this->conflictsContentParameters) || $this->conflictsState === false) {
            ZLog::Write(LOGLEVEL_WARN, "ImportChangesICS->lazyLoadConflicts(): can not load potential conflicting changes in lazymode for conflict detection. Missing information");
            return false;
        }

        if (!$this->conflictsLoaded) {
            ZLog::Write(LOGLEVEL_DEBUG, "ImportChangesICS->lazyLoadConflicts(): loading..");

            // configure an exporter so we can detect conflicts
            $exporter = new ExportChangesICS($this->session, $this->store, $this->folderid);
            $exporter->Config($this->conflictsState);
            $exporter->ConfigContentParameters($this->conflictsContentParameters);
            $exporter->InitializeExporter($this->memChanges);
            while(is_array($exporter->Synchronize()));
            $this->conflictsLoaded = true;
        }
    }

    /**
     * Imports a single message
     *
     * @param string        $id
     * @param SyncObject    $message
     *
     * @access public
     * @return boolean/string - failure / id of message
     * @throws StatusException
     */
    public function ImportMessageChange($id, $message) {
        $parentsourcekey = $this->folderid;
        if($id)
            $sourcekey = hex2bin($id);

        $flags = 0;
        $props = array();
        $props[PR_PARENT_SOURCE_KEY] = $parentsourcekey;

        // set the PR_SOURCE_KEY if available or mark it as new message
        if($id) {
            $props[PR_SOURCE_KEY] = $sourcekey;

            // check for conflicts
            $this->lazyLoadConflicts();
            if($this->memChanges->IsChanged($id)) {
                if ($this->flags & SYNC_CONFLICT_OVERWRITE_PIM) {
                    // in these cases the status SYNC_STATUS_CONFLICTCLIENTSERVEROBJECT should be returned, so the mobile client can inform the end user
                    throw new StatusException(sprintf("ImportChangesICS->ImportMessageChange('%s','%s'): Conflict detected. Data from PIM will be dropped! Server overwrites PIM. User is informed.", $id, get_class($message)), SYNC_STATUS_CONFLICTCLIENTSERVEROBJECT, null, LOGLEVEL_INFO);
                    return false;
                }
                else
                    ZLog::Write(LOGLEVEL_INFO, sprintf("ImportChangesICS->ImportMessageChange('%s','%s'): Conflict detected. Data from Server will be dropped! PIM overwrites server.", $id, get_class($message)));
            }
            if($this->memChanges->IsDeleted($id)) {
                ZLog::Write(LOGLEVEL_INFO, sprintf("ImportChangesICS->ImportMessageChange('%s','%s'): Conflict detected. Data from PIM will be dropped! Object was deleted on server.", $id, get_class($message)));
                return false;
            }
        }
        else
            $flags = SYNC_NEW_MESSAGE;

        if(mapi_importcontentschanges_importmessagechange($this->importer, $props, $flags, $mapimessage)) {
            $this->mapiprovider->SetMessage($mapimessage, $message);
            mapi_message_savechanges($mapimessage);

            if (mapi_last_hresult())
                throw new StatusException(sprintf("ImportChangesICS->ImportMessageChange('%s','%s'): Error, mapi_message_savechanges() failed: 0x%X", $id, get_class($message), mapi_last_hresult()), SYNC_STATUS_SYNCCANNOTBECOMPLETED);

            $sourcekeyprops = mapi_getprops($mapimessage, array (PR_SOURCE_KEY));
            return bin2hex($sourcekeyprops[PR_SOURCE_KEY]);
        }
        else
            throw new StatusException(sprintf("ImportChangesICS->ImportMessageChange('%s','%s'): Error updating object: 0x%X", $id, get_class($message), mapi_last_hresult()), SYNC_STATUS_OBJECTNOTFOUND);
    }

    /**
     * Imports a deletion. This may conflict if the local object has been modified
     *
     * @param string        $id
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function ImportMessageDeletion($id) {
        // check for conflicts
        $this->lazyLoadConflicts();
        if($this->memChanges->IsChanged($id)) {
            ZLog::Write(LOGLEVEL_INFO, sprintf("ImportChangesICS->ImportMessageDeletion('%s'): Conflict detected. Data from Server will be dropped! PIM deleted object.", $id));
        }
        elseif($this->memChanges->IsDeleted($id)) {
            ZLog::Write(LOGLEVEL_INFO, sprintf("ImportChangesICS->ImportMessageDeletion('%s'): Conflict detected. Data is already deleted. Request will be ignored.", $id));
            return true;
        }

        // do a 'soft' delete so people can un-delete if necessary
        if(mapi_importcontentschanges_importmessagedeletion($this->importer, 1, array(hex2bin($id))))
            throw new StatusException(sprintf("ImportChangesICS->ImportMessageDeletion('%s'): Error updating object: 0x%X", $id, mapi_last_hresult()), SYNC_STATUS_OBJECTNOTFOUND);

        return true;
    }

    /**
     * Imports a change in 'read' flag
     * This can never conflict
     *
     * @param string        $id
     * @param int           $flags - read/unread
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function ImportMessageReadFlag($id, $flags) {
        // check for conflicts
        /*
         * Checking for conflicts is correct at this point, but is a very expensive operation.
         * If the message was deleted, only an error will be shown.
         *
        $this->lazyLoadConflicts();
        if($this->memChanges->IsDeleted($id)) {
            ZLog::Write(LOGLEVEL_INFO, sprintf("ImportChangesICS->ImportMessageReadFlag('%s'): Conflict detected. Data is already deleted. Request will be ignored.", $id));
            return true;
        }
         */

        $readstate = array ( "sourcekey" => hex2bin($id), "flags" => $flags);

        if(!mapi_importcontentschanges_importperuserreadstatechange($this->importer, array($readstate) ))
            throw new StatusException(sprintf("ImportChangesICS->ImportMessageReadFlag('%s','%d'): Error setting read state: 0x%X", $id, $flags, mapi_last_hresult()), SYNC_STATUS_OBJECTNOTFOUND);

        return true;
    }

    /**
     * Imports a move of a message. This occurs when a user moves an item to another folder
     *
     * Normally, we would implement this via the 'offical' importmessagemove() function on the ICS importer,
     * but the Zarafa importer does not support this. Therefore we currently implement it via a standard mapi
     * call. This causes a mirror 'add/delete' to be sent to the PDA at the next sync.
     * Manfred, 2010-10-21. For some mobiles import was causing duplicate messages in the destination folder
     * (Mantis #202). Therefore we will create a new message in the destination folder, copy properties
     * of the source message to the new one and then delete the source message.
     *
     * @param string        $id
     * @param string        $newfolder      destination folder
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function ImportMessageMove($id, $newfolder) {
        if (strtolower($newfolder) == strtolower(bin2hex($this->folderid)) )
            throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, source and destination are equal", $id, $newfolder), SYNC_MOVEITEMSSTATUS_SAMESOURCEANDDEST);

        // Get the entryid of the message we're moving
        $entryid = mapi_msgstore_entryidfromsourcekey($this->store, $this->folderid, hex2bin($id));
        if(!$entryid)
            throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, unable to resolve source message id", $id, $newfolder), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);

        //open the source message
        $srcmessage = mapi_msgstore_openentry($this->store, $entryid);
        if (!$srcmessage)
            throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, unable to open source message: 0x%X", $id, $newfolder, mapi_last_hresult()), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);

        // get correct mapi store for the destination folder
        $dststore = ZPush::GetBackend()->GetMAPIStoreForFolderId(ZPush::GetAdditionalSyncFolderStore($newfolder), $newfolder);
        if ($dststore === false)
            throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, unable to open store of destination folder", $id, $newfolder), SYNC_MOVEITEMSSTATUS_INVALIDDESTID);

        $dstentryid = mapi_msgstore_entryidfromsourcekey($dststore, hex2bin($newfolder));
        if(!$dstentryid)
            throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, unable to resolve destination folder", $id, $newfolder), SYNC_MOVEITEMSSTATUS_INVALIDDESTID);

        $dstfolder = mapi_msgstore_openentry($dststore, $dstentryid);
        if(!$dstfolder)
            throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, unable to open destination folder", $id, $newfolder), SYNC_MOVEITEMSSTATUS_INVALIDDESTID);

        $newmessage = mapi_folder_createmessage($dstfolder);
        if (!$newmessage)
            throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, unable to create message in destination folder: 0x%X", $id, $newfolder, mapi_last_hresult()), SYNC_MOVEITEMSSTATUS_INVALIDDESTID);

        // Copy message
        mapi_copyto($srcmessage, array(), array(), $newmessage);
        if (mapi_last_hresult())
            throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, copy to destination message failed: 0x%X", $id, $newfolder, mapi_last_hresult()), SYNC_MOVEITEMSSTATUS_CANNOTMOVE);

        $srcfolderentryid = mapi_msgstore_entryidfromsourcekey($this->store, $this->folderid);
        if(!$srcfolderentryid)
            throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, unable to resolve source folder", $id, $newfolder), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);

        $srcfolder = mapi_msgstore_openentry($this->store, $srcfolderentryid);
        if (!$srcfolder)
            throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, unable to open source folder: 0x%X", $id, $newfolder, mapi_last_hresult()), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);

        // Save changes
        mapi_savechanges($newmessage);
        if (mapi_last_hresult())
            throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, mapi_savechanges() failed: 0x%X", $id, $newfolder, mapi_last_hresult()), SYNC_MOVEITEMSSTATUS_CANNOTMOVE);

        // Delete the old message
        if (!mapi_folder_deletemessages($srcfolder, array($entryid)))
            throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, delete of source message failed: 0x%X. Possible duplicates.", $id, $newfolder, mapi_last_hresult()), SYNC_MOVEITEMSSTATUS_SOURCEORDESTLOCKED);

        $sourcekeyprops = mapi_getprops($newmessage, array (PR_SOURCE_KEY));
        if (isset($sourcekeyprops[PR_SOURCE_KEY]) && $sourcekeyprops[PR_SOURCE_KEY])
            return  bin2hex($sourcekeyprops[PR_SOURCE_KEY]);

        return false;
    }


    /**----------------------------------------------------------------------------------------------------------
     * Methods for HierarchyExporter
     */

    /**
     * Imports a change on a folder
     *
     * @param object        $folder     SyncFolder
     *
     * @access public
     * @return string       id of the folder
     * @throws StatusException
     */
    public function ImportFolderChange($folder) {
        $id = isset($folder->serverid)?$folder->serverid:false;
        $parent = $folder->parentid;
        $displayname = u2wi($folder->displayname);
        $type = $folder->type;

        if (Utils::IsSystemFolder($type))
            throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, system folder can not be created/modified", Utils::PrintAsString($folder->serverid), $folder->parentid, $displayname), SYNC_FSSTATUS_SYSTEMFOLDER);

        // create a new folder if $id is not set
        if (!$id) {
            $parentfentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($parent));
            if (!$parentfentryid)
                throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, unable to open parent folder", Utils::PrintAsString(false), $folder->parentid, $displayname), SYNC_FSSTATUS_PARENTNOTFOUND);

            $parentfolder = mapi_msgstore_openentry($this->store, $parentfentryid);
            if (!$parentfolder)
                throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, unable to open parent folder", Utils::PrintAsString(false), $folder->parentid, $displayname), SYNC_FSSTATUS_PARENTNOTFOUND);

            //  mapi_folder_createfolder() fails if a folder with this name already exists -> MAPI_E_COLLISION
            $newfolder = mapi_folder_createfolder($parentfolder, $displayname, "");
            if (mapi_last_hresult())
                throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, mapi_folder_createfolder() failed: 0x%X", Utils::PrintAsString(false), $folder->parentid, $displayname, mapi_last_hresult()), SYNC_FSSTATUS_FOLDEREXISTS);

            mapi_setprops($newfolder, array(PR_CONTAINER_CLASS => MAPIUtils::GetContainerClassFromFolderType($type)));

            $props =  mapi_getprops($newfolder, array(PR_SOURCE_KEY));
            if (isset($props[PR_SOURCE_KEY])) {
                $sourcekey = bin2hex($props[PR_SOURCE_KEY]);
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("Created folder '%s' with id: '%s'", $displayname, $sourcekey));
                return $sourcekey;
            }
            else
                throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, folder created but PR_SOURCE_KEY not available: 0x%X", Utils::PrintAsString($folder->serverid), $folder->parentid, $displayname, mapi_last_hresult()), SYNC_FSSTATUS_SERVERERROR);
            return false;
        }

        if (! mapi_importhierarchychanges_importfolderchange($this->importer, array(PR_SOURCE_KEY => hex2bin($id), PR_PARENT_SOURCE_KEY => hex2bin($parent), PR_DISPLAY_NAME => $displayname)))
            throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, unable import folder change", Utils::PrintAsString($folder->serverid), $folder->parentid, $displayname), SYNC_FSSTATUS_UNKNOWNERROR);

        ZLog::Write(LOGLEVEL_DEBUG, "Imported changes for folder: $id");
        return $id;
    }

    /**
     * Imports a folder deletion
     *
     * @param string        $id
     * @param string        $parent id is ignored in ICS
     *
     * @access public
     * @return int          SYNC_FOLDERHIERARCHY_STATUS
     * @throws StatusException
     */
    public function ImportFolderDeletion($id, $parent = false) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesICS->ImportFolderDeletion('%s','%s'): importing folder deletetion", $id, $parent));

        $folderentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($id));
        if(!$folderentryid)
            throw new StatusException(sprintf("ImportChangesICS->ImportFolderDeletion('%s','%s'): Error, unable to resolve folder", $id, $parent, mapi_last_hresult()), SYNC_FSSTATUS_FOLDERDOESNOTEXIST);

        // get the folder type from the MAPIProvider
        $type = $this->mapiprovider->GetFolderType($folderentryid);

        if (Utils::IsSystemFolder($type))
            throw new StatusException(sprintf("ImportChangesICS->ImportFolderDeletion('%s','%s'): Error deleting system/default folder", $id, $parent), SYNC_FSSTATUS_SYSTEMFOLDER);

        $ret = mapi_importhierarchychanges_importfolderdeletion ($this->importer, 0, array(PR_SOURCE_KEY => hex2bin($id)));
        if (!$ret)
            throw new StatusException(sprintf("ImportChangesICS->ImportFolderDeletion('%s','%s'): Error deleting folder: 0x%X", $id, $parent, mapi_last_hresult()), SYNC_FSSTATUS_SERVERERROR);

        return $ret;
    }
}
?>