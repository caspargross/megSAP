<?php

/** 
	@page create_circos_plot
 */
require_once(dirname($_SERVER['SCRIPT_FILENAME']) . "/../Common/all.php");

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

$parser = new ToolBase("create_circos_plot", "Creates a Circos plot with CN, CNVs, ROHs and BAFs of the current sample.");

$parser->addString("folder", "Analysis data folder.", false);
$parser->addString("name", "Base file name, typically the processed sample ID (e.g. 'GS120001_01').", false);

// optional
$parser->addString("build", "The genome build to use. The genome must be indexed for BWA!", true, "GRCh37");
$parser->addString("cnv_min_ll", "Minimum loglikelyhood for CNVs to be shown in the plot.", true, 20);
$parser->addString("cnv_min_nor", "Minimum number of regions for CNVs to be shown in the plot.", true, 3);
$parser->addString("cnv_max_af", "Maximum IMGAG allele frequency for CNVs to be shown in the plot.", true, 0.5);
$parser->addString("cnv_min_length", "Minimimal CNV length (in kb) for CNVs to be shown in the plot.", true, 15.0);
$parser->addString("roh_min_length", "Minimimal ROH length (in kb) for ROHs to be shown in the plot.", true, 100.0);

extract($parser->parse($argv));

// get Cicos config and genome files 
$karyotype_file = repository_basedir() . "/data/misc/circos/karyotype.human.$build.txt";
if (!file_exists($karyotype_file)) 
{
    trigger_error("No karyotype file for build $build found!", E_USER_ERROR);
}
$circos_template_file = repository_basedir() . "/data/misc/circos/config_template.conf";
if (!file_exists($circos_template_file)) 
{
    trigger_error("No Circos template file found at \"$circos_template_file\"!", E_USER_ERROR);
}
$chr_file = repository_basedir() . "/data/misc/circos/chr_region.$build.txt";
if (!file_exists($chr_file)) 
{
    trigger_error("No chr region file found at \"$chr_file\"!", E_USER_ERROR);
}
$circos_housekeeping_file = repository_basedir() . "/data/misc/circos/housekeeping.conf";
if (!file_exists($circos_housekeeping_file)) 
{
    trigger_error("No Circos config file found at \"$circos_housekeeping_file\"!", E_USER_ERROR);
}

// get telomere file
$telomere_file = repository_basedir() . "/data/misc/centromer_telomer_hg19.bed";
if (!file_exists($telomere_file)) 
{
    trigger_error("No telomere file found at \"$telomere_file\"!", E_USER_ERROR);
}


// preprocess data
// parse CN file and generate temp file for circos plot

// clinCNV seg file:
$seg_file = "$folder/${name}_cnvs_clincnv.seg";
if (!file_exists($seg_file)) 
{
    // CnvHunter seg file:
    $seg_file = "$folder/${name}_cnvs.seg";
    if (!file_exists($seg_file)) 
    {
        trigger_error("No SEG file found!", E_USER_ERROR);
    }
}

$cn_temp_file = $parser->tempFile("_cn.seg");

$input_fh = fopen2($seg_file, "r");
$output_fh = fopen2($cn_temp_file, "w");

if ($input_fh) 
{
    while (($buffer = fgets($input_fh)) !== FALSE) 
    {
        # skip comments and header
        if (starts_with($buffer, "#")) continue;
        if (starts_with($buffer, "ID\tchr\tstart\tend")) continue;
        $row = explode("\t", $buffer);

        # skip undefined segments 
        if ((float) $row[5] < 0.0) continue;
        if ($row[5] == "QC failed") continue;

        $cn = min(4.0, (float) $row[5]);

        # write modified seg file to temp
        fwrite($output_fh, $row[1] . "\t" . $row[2] . "\t" . $row[3] . "\t$cn\n");
    }
    fclose($input_fh);
    fclose($output_fh);
}
else 
{
    if ($error)    trigger_error("Could not open file $seg_file.", E_USER_ERROR);
    return  "Could not open file $seg_file.";
}

// parse CNVs file and generate temp file for circos plot


// clinCNV CNV file:
$cnv_hunter = false;
$cnv_file = "$folder/${name}_cnvs_clincnv.tsv";
if (!file_exists($cnv_file)) 
{
    // CnvHunter CNV file:
    $cnv_file = "$folder/${name}_cnvs.tsv";
    $cnv_hunter = true;
    if (!file_exists($cnv_file)) 
    {
        trigger_error("No CNV file found!", E_USER_ERROR);
    }
}

$cnv_temp_file_del = $parser->tempFile("_cnv_del.tsv");
$cnv_temp_file_dup = $parser->tempFile("_cnv_dup.tsv");

// load CNV file as matrix
$cnv_matrix = Matrix::fromTSV($cnv_file);
// get column indices
$chr_idx = $cnv_matrix->getColumnIndex("chr");
$start_idx = $cnv_matrix->getColumnIndex("start");
$end_idx = $cnv_matrix->getColumnIndex("end");
if ($cnv_hunter)
{
    $cn_idx = $cnv_matrix->getColumnIndex("region_copy_numbers");
    $nor_idx = $cnv_matrix->getColumnIndex("region_count");
    $length_idx = $cnv_matrix->getColumnIndex("size");
}
else
{
    $cn_idx = $cnv_matrix->getColumnIndex("CN_change");
    $ll_idx = $cnv_matrix->getColumnIndex("loglikelihood");
    $nor_idx = $cnv_matrix->getColumnIndex("no_of_regions");
    $overlap_af_idx = $cnv_matrix->getColumnIndex("overlap af_genomes_imgag");
    $length_idx = $cnv_matrix->getColumnIndex("length_KB");
}

$output_fh_del = fopen2($cnv_temp_file_del, "w");
$output_fh_dup = fopen2($cnv_temp_file_dup, "w");
for ($row_idx=0; $row_idx < $cnv_matrix->rows(); $row_idx++) 
{ 
    // filter CNV
    // no_of_regions
    if ((int) $cnv_matrix->get($row_idx, $nor_idx) < $cnv_min_nor) continue;
    // CnvHunter:
    if ($cnv_hunter)
    {
        // CNV size
        if (((float) $cnv_matrix->get($row_idx, $length_idx) / 1000.0) < $cnv_min_length) continue;
    }
    else
    // ClinCNV
    {
        // loglikelihood
        if ((int) $cnv_matrix->get($row_idx, $ll_idx) < $cnv_min_ll) continue;
        // overlap af (imgag)
        if ((float) $cnv_matrix->get($row_idx, $overlap_af_idx) > $cnv_max_af) continue;
        // CNV size
        if ((float) $cnv_matrix->get($row_idx, $length_idx) < $cnv_min_length) continue;
    }
    


    // write modified cnv files to temp
    if ((int) $cnv_matrix->get($row_idx, $cn_idx) < 2) 
    {
        fwrite($output_fh_del, $cnv_matrix->get($row_idx, $chr_idx)."\t".$cnv_matrix->get($row_idx, $start_idx)."\t".$cnv_matrix->get($row_idx, $end_idx)."\n");
    } 
    else 
    {
        fwrite($output_fh_dup, $cnv_matrix->get($row_idx, $chr_idx)."\t".$cnv_matrix->get($row_idx, $start_idx)."\t".$cnv_matrix->get($row_idx, $end_idx)."\n");
    }
}

fclose($output_fh_del);
fclose($output_fh_dup);

// parse ROHs file and generate temp file for circos plot
$roh_file = "$folder/${name}_rohs.tsv";

$roh_temp_file = $parser->tempFile("_roh.tsv");

if (!file_exists($roh_file)) 
{
    trigger_error("WARNING: No ROH file found!", E_USER_WARNING);
    
    // create empty temp file
    touch($roh_temp_file);
}
else
{
    $input_fh = fopen2($roh_file, "r");
    $output_fh = fopen2($roh_temp_file, "w");

    if ($input_fh) 
    {
        while (($buffer = fgets($input_fh)) !== FALSE) 
        {
            // skip comments and header
            if (starts_with($buffer, "#")) continue;
            $row = explode("\t", $buffer);

            // skip small ROHs 
            if ((float) $row[5] < $roh_min_length) continue;

            $cn = min(4.0, (float) $row[5]);

            // write modified roh file to temp
            fwrite($output_fh, $row[0] . "\t" . $row[1] . "\t" . $row[2] . "\n");
        }
        fclose($input_fh);
        fclose($output_fh);
    } 
    else 
    {
        if ($error) trigger_error("Could not open file $roh_file.", E_USER_ERROR);
        return  "Could not open file $roh_file.";
    }
}

// parse BAFs file and generate temp file for circos plot
$baf_file = "$folder/${name}_bafs.igv";

$baf_temp_file = $parser->tempFile("_bafs.tsv");

if (!file_exists($baf_file)) 
{
    trigger_error("WARNING: No BAF file found!", E_USER_WARNING);

    // create empty temp file
    touch($baf_temp_file);
}
else
{
    $input_fh = fopen2($baf_file, "r");
    $output_fh = fopen2($baf_temp_file, "w");

    if ($input_fh) 
    {
        while (($buffer = fgets($input_fh)) !== FALSE) 
        {
            // skip comments and header
            if (starts_with($buffer, "#")) continue;
            if (starts_with($buffer, "Chromosome\tStart\tEnd")) continue;
            $row = explode("\t", $buffer);

            // write modified baf file to temp
            fwrite($output_fh, $row[0] . "\t" . $row[1] . "\t" . $row[2] . "\t" . $row[4] . "\n");
        }
        fclose($input_fh);
        fclose($output_fh);
    } 
    else 
    {
        if ($error) trigger_error("Could not open file $baf_file.", E_USER_ERROR);
        return  "Could not open file $baf_file.";
    }
}



// create dummy file to add sample name to plot
$sample_label = $parser->tempFile("_sample_label.txt");
$output_fh = fopen2($sample_label, "w");
fwrite($output_fh, "chr1\t1\t1000000\t$name\n");
fclose($output_fh);

// create modified Circos config file
$file_names = array();
$file_names["[OUTPUT_FOLDER]"] = $folder;
$file_names["[PNG_OUTPUT]"] = "${name}_circos.png";
$file_names["[KARYOTYPE_FILE]"] = $karyotype_file;
$file_names["[CHR_FILE]"] = $chr_file;
$file_names["[TELOMERE_FILE]"] = $telomere_file;
$file_names["[BAF_FILE]"] = $baf_temp_file;
$file_names["[CN_FILE]"] = $cn_temp_file;
$file_names["[CNV_DUP_FILE]"] = $cnv_temp_file_dup;
$file_names["[CNV_DEL_FILE]"] = $cnv_temp_file_del;
$file_names["[ROH_FILE]"] = $roh_temp_file;
$file_names["[SAMPLE_LABEL]"] = $sample_label;
$file_names["[LABEL_FOLDER]"] = repository_basedir()."/data/misc/circos";
$file_names["[HOUSEKEEPING_FILE]"] = $circos_housekeeping_file;

// parse circos template file and replace file names
$circos_config_file = "$folder/${name}_circos_config.conf";

$input_fh = fopen2($circos_template_file, "r");
$output_fh = fopen2($circos_config_file, "w");

if (!$output_fh) 
{
    if ($error)    trigger_error("Could not open file $circos_config_file.", E_USER_ERROR);
    return  "Could not open file $circos_config_file.";
}
if ($input_fh) 
{
    while (($buffer = fgets($input_fh)) !== FALSE) 
    {
        // replace file names
        $modified_line = strtr($buffer, $file_names);

        // write modified baf file to temp
        fwrite($output_fh, $modified_line);
    }
    fclose($input_fh);
    fclose($output_fh);
} 
else 
{
    if ($error) trigger_error("Could not open file $circos_template_file.", E_USER_ERROR);
    return  "Could not open file $circos_template_file.";
}


// create Circos plot
$parser->exec(get_path("circos"), "-conf $circos_config_file", true);