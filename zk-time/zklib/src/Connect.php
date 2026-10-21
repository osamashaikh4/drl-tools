<?php

namespace ZK;

use ZKLib;
use ErrorException;
use Exception;

class Connect
{
    /**
     * @param ZKLib $self
     * @return bool
     */
    public function connect(ZKLib $self)
    {
        $self->_section = __METHOD__;

        $command = Util::CMD_CONNECT;
        $command_string = '';
        $chksum = 0;
        $session_id = 0;
        $reply_id = -1 + Util::USHRT_MAX;

        $buf = Util::createHeader($command, $chksum, $session_id, $reply_id, $command_string);

        if (!$self->_zkclient) {
            return false;
        }
        fwrite($self->_zkclient, $buf);

        try {
            $self->_data_recv = (string) @fread($self->_zkclient, 1024);
            if (strlen($self->_data_recv) > 0) {
                $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6', substr($self->_data_recv, 0, 8));

                $session = hexdec($u['h6'] . $u['h5']);
                if (empty($session)) {
                    return false;
                }

                $self->_session_id = $session;
                return Util::checkValid($self->_data_recv);
            } else {
                return false;
            }
        } catch (ErrorException $e) {
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @param ZKLib $self
     * @return bool
     */
    public function disconnect(ZKLib $self)
    {
        $self->_section = __METHOD__;

        $command = Util::CMD_EXIT;
        $command_string = '';
        $chksum = 0;
        $session_id = $self->_session_id;

        $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6/H2h7/H2h8', substr($self->_data_recv, 0, 8));
        $reply_id = hexdec($u['h8'] . $u['h7']);

        $buf = Util::createHeader($command, $chksum, $session_id, $reply_id, $command_string);


        fwrite($self->_zkclient, $buf);
        try {
            $self->_data_recv = (string) @fread($self->_zkclient, 1024);

            $self->_session_id = 0;
            return Util::checkValid($self->_data_recv);
        } catch (ErrorException $e) {
            return false;
        } catch (Exception $e) {
            return false;
        }
    }
}