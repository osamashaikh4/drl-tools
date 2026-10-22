<?php

namespace ZK;

use ZKLib;

class Attendance
{
    /**
     * @param ZKLib $self
     * @return array [uid, id, state, timestamp]
     */
    public function get(ZKLib $self)
    {
        $self->_section = __METHOD__;

        $command = Util::CMD_ATT_LOG_RRQ;
        $command_string = '';

        $session = $self->_command($command, $command_string, Util::COMMAND_TYPE_DATA);
        if ($session === false) {
            return [];
        }

        $attData = Util::recData($self);

        $attendance = [];
        if (!empty($attData)) {
            $attData = substr($attData, 10);

            // drl-tools: step through by position; cutting 40 bytes off the front each time copied
            // the rest of the log for every record (46 s of 70 for 78,891 records on New Lab Bld).
            $len = strlen($attData);
            for ($pos = 0; $len - $pos > 40; $pos += 40) {
                $u = unpack('H78', substr($attData, $pos, 39));

                $u1 = hexdec(substr($u[1], 4, 2));
                $u2 = hexdec(substr($u[1], 6, 2));
                $uid = $u1 + ($u2 * 256);
                $id = hex2bin(substr($u[1], 8, 18));
                $id = str_replace(chr(0), '', $id);
                $state = hexdec(substr($u[1], 56, 2));
                $timestamp = Util::decodeTime(hexdec(Util::reverseHex(substr($u[1], 58, 8))));
                $type = hexdec(Util::reverseHex(substr($u[1], 66, 2)));

                $attendance[] = [
                    'uid' => $uid,
                    'id' => $id,
                    'state' => $state,
                    'timestamp' => $timestamp,
                    'type' => $type
                ];
            }
        }

        return $attendance;
    }

    /**
     * @param ZKLib $self
     * @return bool|mixed
     */
    public function clear(ZKLib $self)
    {
        $self->_section = __METHOD__;

        $command = Util::CMD_CLEAR_ATT_LOG;
        $command_string = '';

        return $self->_command($command, $command_string);
    }
}
