<?php

$cli->output( 'Start script for converting pdf to image...' );
$cli->output( 'Time: ' . date( 'd.m.Y H:i', time() ) );
//login as admin
$user = eZUser::fetch( eZINI::instance()->variable( 'UserSettings', 'UserCreatorID' ) );
$user->loginCurrent();
$bookscans = eZPendingActions::fetchByAction( 'bookscan' );
if( count( $bookscans ) > 0 )
{
    $xrowbookscan_ini = eZINI::instance( 'xrowbookscan.ini' );
    if( $xrowbookscan_ini->hasVariable( 'Settings', 'ClassNameParentForBookscan' ) )
    {
        $sectionID = $xrowbookscan_ini->variable( 'Settings', 'CreateBookscanSection' );
        $defaultVars = array();
        $defaultVars['className'] = $xrowbookscan_ini->variable( 'Settings', 'ClassNameForPreview' );
        $defaultVars['classAttributeNameFile'] = $xrowbookscan_ini->variable( 'Settings', 'ClassAttributeNameForPDF' );
        $defaultVars['classAttributeNameImage'] = $xrowbookscan_ini->variable( 'Settings', 'ClassAttributeNameForConvertedImage' );
        $classNames = $xrowbookscan_ini->variable( 'Settings', 'ClassNameParentForBookscan' );
        if( count( $classNames ) > 0 )
        {
            foreach( $classNames as $className )
            {
                if( $xrowbookscan_ini->hasSection( 'ParentForBookscan_' . $className ) )
                {
                    $settingsBlock = $xrowbookscan_ini->BlockValues['ParentForBookscan_' . $className];
                    if( isset( $settingsBlock['AttributeNameFileForIndexing'] ) && $settingsBlock['AttributeNameFileForIndexing'] != '' )
                    {
                        $attributeNameForFileForIndexing = $settingsBlock['AttributeNameFileForIndexing'];
                        $alreadyExists = array();
                        foreach( $bookscans as $bookscan )
                        {
                            if( $bookscan instanceof eZPendingActions )
                            {
                                // check if this id is also converted
                                $obj_id = $bookscan->attribute( 'param' );
                                if( !in_array( $obj_id, $alreadyExists ) )
                                {
                                    $alreadyExistst[] = $obj_id;
                                    $object = eZContentObject::fetch( $obj_id );
                                    if( $object instanceof eZContentObject )
                                    {
                                        if( $className == $object->ClassIdentifier )
                                        {
                                            $cli->output( 'Get object with ID ' . $obj_id );
                                            $parentNode = $object->mainNode();
                                            // check if parent has already children class bookscan
                                            $params = array( 'Depth'                    => 1,
                                                             'Offset'                   => 0,
                                                             'ClassFilterType'          => 'include',
                                                             'ClassFilterArray'         => array( $defaultVars['className'] ),
                                                             'IgnoreVisibility'         => true );
                                            $children_count = eZContentObjectTreeNode::subTreeCountByNodeID( $params, $parentNode->NodeID );
                                            if( $children_count > 0 )
                                            {
                                                $cli->output( 'Deleting ' . $children_count . ' children of "' . $parentNode->Name . '"...' );
                                                // remove all children before creating new
                                                $children = eZContentObjectTreeNode::subTreeByNodeID( $params, $parentNode->NodeID );
                                                $deleteNodeIDs = array();
                                                foreach( $children as $child )
                                                {
                                                    $deleteNodeIDs[] = $child->NodeID;
                                                }
                                                if( count( $deleteNodeIDs ) > 0 )
                                                {
                                                    if ( eZOperationHandler::operationIsAvailable( 'content_delete' ) )
                                                    {
                                                        $operationResult = eZOperationHandler::execute( 'content',
                                                                                                        'delete',
                                                                                                        array( 'node_id_list' => $deleteNodeIDs,
                                                                                                               'move_to_trash' => false ),
                                                                                                        null, true );
                                                    }
                                                    else
                                                    {
                                                        eZContentOperationCollection::deleteObject( $deleteNodeIDs, false );
                                                    }
                                                }
                                            }
                                            $dataMap = $object->dataMap();
                                            if( isset( $dataMap[$attributeNameForFileForIndexing] ) )
                                            {
                                                $attributeFileForIndexing = $dataMap[$attributeNameForFileForIndexing]->content();
                                                if( $attributeFileForIndexing instanceof eZBinaryFile )
                                                {
                                                    // copy pdf to local for explode this
                                                    $filePath = $attributeFileForIndexing->filepath();
                                                    $file = eZClusterFileHandler::instance( $filePath );
                                                    if ( is_object( $file ) )
                                                    {
                                                        $cli->output( 'Get pdf (' . $filePath . ') to explode into single pages' );
                                                        $file->fileFetch( $filePath );
                                                        $version = $attributeFileForIndexing->Version;
                                                        $pdfTmp = new FPDI();
                                                        $pageCount = $pdfTmp->setSourceFile( $filePath );
                                                        for( $i = 1; $i <= $pageCount; $i++)
                                                        {
                                                            $pdf = new FPDI();
                                                            $pdf->setSourceFile( $filePath );
                                                            $data = array();
                                                            // get one page from original pdf
                                                            $tplIdx = $pdf->importPage( $i );
                                                            if( $tplIdx )
                                                            {
                                                                $cli->output( '' );
                                                                $cli->output( 'Start creating page no' . $i . '(' . $filePathNew . ')' );
                                                                $pdf->addPage();
                                                                $pdf->useTemplate( $tplIdx );
                                                                $filePathNew = preg_replace( '/.pdf/', '_' . $i . '.pdf', $filePath );
                                                                // create one-page-pdf xy from the original pdf to local
                                                                $pdf->Output( $filePathNew, 'F' );
                                                                if ( !file_exists( $filePathNew ) )
                                                                {
                                                                    eZDebug::writeError( 'File ' . $filePathNew . ' does not exist', 'Cronjob xrowbookscancreate.php' );
                                                                    $cli->error( 'File ' . $filePathNew . ' does not exist, Cronjob xrowbookscancreate.php' );
                                                                    $script->shutdown( 1 );
                                                                }
                                                                else
                                                                {
                                                                    $cli->output( 'Created...' );
                                                                    // convert pdf to image
                                                                    $imageFilePathNew = preg_replace( '/.pdf/', '.png', $filePathNew );
                                                                    $imageFilePathNewTmp = preg_replace( '/.pdf/', 'tmp.png', $filePathNew );
                                                                    $cli->output( 'Start converting page no' . $i . ' to an image (' . $imageFilePathNew . ')' );
                                                                    $status = convertPDFtoImage( $filePathNew, $imageFilePathNewTmp, $settingsBlock, $script );
                                                                    if( $status )
                                                                    {
                                                                        $cli->output( 'Converted...' );
                                                                        $data[$defaultVars['classAttributeNameFile']] = $filePathNew;
                                                                        $data[$defaultVars['classAttributeNameImage']] = $imageFilePathNew;
                                                                        $data['name'] = $parentNode->Name . ' ' . $i;
                                                                        // create new object
                                                                        try
                                                                        {
                                                                            $cli->output( 'Start creating new object class ' . $defaultVars['className'] . ' under ' . $parentNode->Name . '(' . $parentNode->NodeID . ')' );
                                                                            $xrowBookScan = new xrowBookScan( $defaultVars, $parentNode );
                                                                            $contentObject = $xrowBookScan->create( $i, $data, $user->attribute( 'contentobject_id' ), $sectionID );
                                                                            if( $contentObject instanceof eZContentObject )
                                                                            {
                                                                                // delete pdf_xy.pdf and image_xy.png
                                                                                unlink($filePathNew);
                                                                                unlink($imageFilePathNew);
                                                                                unlink($imageFilePathNewTmp);
                                                                                $cli->output( 'Deleted tmp files' );
                                                                            }
                                                                        }
                                                                        catch ( Exception $e )
                                                                        {
                                                                            eZDebug::writeError( $e->getMessage() . ', Line: ' . $e->getLine(), 'Cronjob xrowbookscancreate.php' );
                                                                            $cli->error( $e->getMessage() . ', Line: ' . $e->getLine() . ', Cronjob xrowbookscancreate.php' );
                                                                            $script->shutdown( 1 );
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        $file->deleteLocal();
                                                        $filterConds = array( 'action' => 'bookscan', 'param' => $obj_id );
                                                        eZPendingActions::removeObject( eZPendingActions::definition(), $filterConds );
                                                    }
                                                    else
                                                    {
                                                        eZDebug::writeError( 'File ' . $filePath . ' does not exist', 'Cronjob xrowbookscan.php' );
                                                        $cli->error( 'File ' . $filePath . ' does not exist, Cronjob xrowbookscancreate.php' );
                                                        $script->shutdown( 1 );
                                                    }
                                                }
                                                
                                            }
                                            else
                                            {
                                                eZDebug::writeError( 'Attribute ' . $attributeNameForFileForIndexing . ' does not exist in data map', 'Cronjob xrowbookscan.php' );
                                                $cli->error( 'Attribute ' . $attributeNameForFileForIndexing . ' does not exist in data map, Cronjob xrowbookscancreate.php' );
                                                $script->shutdown( 1 );
                                            }
                                        }
                                    }
                                    else
                                    {
                                        eZDebug::writeError( 'Object with contentobject_id ' . $obj_id . ' does not exist', 'Cronjob xrowbookscan.php' );
                                        $cli->error( 'Object with contentobject_id ' . $obj_id . ' does not exist, Cronjob xrowbookscancreate.php' );
                                        $script->shutdown( 1 );
                                    }
                                }
                            }
                        }
                    }
                    else
                    {
                        eZDebug::writeError( 'No AttributeNameFileForIndexing in Block Settings, xrowbookscan.ini', 'Cronjob xrowbookscan.php' );
                        $cli->error( 'No AttributeNameFileForIndexing in Block Settings, xrowbookscan.ini, Cronjob xrowbookscancreate.php' );
                        $script->shutdown( 1 );
                    }
                }
                else
                {
                    eZDebug::writeError( 'No SettingsBlock for the class ' . $className . ', xrowbookscan.ini', 'Cronjob xrowbookscan.php' );
                    $cli->error( 'No SettingsBlock for the class ' . $className . ', xrowbookscan.ini, Cronjob xrowbookscancreate.php' );
                    $script->shutdown( 1 );
                }
            }
        }
    }
}
else
{
    $cli->output( 'There is nothing to do...' );
}
$cli->output( '' );
$cli->output( 'Ready' );
$cli->output( 'Time: ' . date( 'd.m.Y H:i', time() ) );

function convertPDFtoImage( $pdfFileName, $imageFileName, $settingsBlock, $script )
{
    global $cli;
    $return = false;
    $eZImageShellHandler = eZImageShellHandler::createFromINI( 'ImageMagick' );
    $convert = $eZImageShellHandler->Path . DIRECTORY_SEPARATOR . $eZImageShellHandler->Executable;
    $composite = $eZImageShellHandler->Path . DIRECTORY_SEPARATOR . 'composite';
    if( isset( $settingsBlock['ConvertFilterPDFPre'] ) )
        $filterPre = implode( ' ', $settingsBlock['ConvertFilterPDFPre'] );
    else
        $filterPre = '-density 150';
    if( isset( $settingsBlock['ConvertFilterPDFPost'] ) )
        $filterPost = implode( ' ', $settingsBlock['ConvertFilterPDFPost'] );
    else
        $filterPost = '-quality 90';
    $systemString = $convert . ' ' . $filterPre . ' ' . $pdfFileName . ' ' . $filterPost . ' ' . $imageFileName;
    $cli->output( 'convertPDFtoImage system: ' . $systemString );
    system( $systemString, $returnCode );
    if ( $returnCode == 0 )
    {
        if ( !file_exists( $imageFileName ) )
        {
            eZDebug::writeError( 'Unknown source file: ' . $imageFileName . ' after converting', __METHOD__ );
            $cli->error( 'Unknown source file: ' . $imageFileNameWatermark . ' after converting, ' . __METHOD__ );
            $script->shutdown( 1 );
        }
        else
        {
            if( changeFilePermissions( $imageFileName ) )
            {
                if( isset( $settingsBlock['WatermarkSettings'] ) && $settingsBlock['WatermarkSettings'] == 'enabled' )
                {
                    $imageFileNameWatermark = $settingsBlock['WatermarkFileName'];
                    if ( !file_exists( $imageFileNameWatermark ) )
                    {
                        eZDebug::writeError( 'Unknown destination file: ' . $imageFileNameWatermark . ' for watermark', __METHOD__ );
                        $cli->error( 'Unknown destination file: ' . $imageFileNameWatermark . ' for watermark, ' . __METHOD__ );
                        $script->shutdown( 1 );
                    }
                    else
                    {
                        $imageFileNameNew = preg_replace( '/tmp.png/', '.png', $imageFileName );
                        if( isset( $settingsBlock['CompositeFilterWatermarkPre'] ) )
                            $compositeFilterPre = implode( ' ', $settingsBlock['CompositeFilterWatermarkPre'] );
                        else
                            $compositeFilterPre = '-gravity NorthWest';
                        if( isset( $settingsBlock['CompositeFilterWatermarkPost'] ) )
                            $compositeFilterPost = implode( ' ', $settingsBlock['CompositeFilterWatermarkPost'] );
                        else
                            $compositeFilterPost = '-quality 90';
                        $watermarkSystemString = $composite . ' ' . $compositeFilterPre . ' ' . $imageFileNameWatermark . ' ' . $imageFileName . ' ' . $compositeFilterPost .' ' . $imageFileNameNew;
                        $cli->output( 'convertPDFtoImage system watermark: ' . $watermarkSystemString );
                        system( $watermarkSystemString, $watermarkReturnCode );
                        if ( $watermarkReturnCode == 0 )
                            $return = true;
                        else
                        {
                            eZDebug::writeError( "Failed executing: $watermarkSystemString, Error code: $watermarkReturnCode", __METHOD__ );
                            $cli->error( "Failed executing: $watermarkSystemString, Error code: $watermarkReturnCode, " . __METHOD__ );
                            $script->shutdown( 1 );
                        }
                    }
                }
                else
                    $return = true;
            }
        }
    }
    else
    {
        eZDebug::writeError( "Failed executing: $systemString, Error code: $returnCode", __METHOD__ );
        $cli->error( "Failed executing: $systemString, Error code: $returnCode, " . __METHOD__ );
        $script->shutdown( 1 );
    }
    return $return;
}
/*function createWatermark()
{
    convert -size 150x25 xc:grey30 -font Arial -pointsize 10 -gravity center -draw "fill grey70  text 0,0  '© Holzwerken'" stempelvordergrund.png
}*/
function changeFilePermissions( $filepath )
{
    if ( !file_exists( $filepath ) )
        return false;
    $ini = eZINI::instance( 'image.ini' );
    $perm = $ini->variable( "FileSettings", "ImagePermissions" );
    $success = false;
    $oldmask = umask( 0 );
    if ( !chmod( $filepath, octdec( $perm ) ) )
        eZDebug::writeError( "Chmod $perm $filepath failed", __METHOD__ );
    else
        $success = true;
    umask( $oldmask );
    return $success;
}