<?php

Class GetAltiServicesFromDB{
    protected $Srid = "";
    protected $AltiProfileTable = "";
    protected $Altisource = "";

    /**
     * Get config parameters
    **/
    function getModuleConfig(){
        $localConfig = jApp::configPath('localconfig.ini.php');
        $localConfig = new jIniFileModifier($localConfig);        
        $this->Srid = $localConfig->getValue('srid', 'altiProfil');
        $this->AltiProfileTable = $localConfig->getValue('altiProfileTable', 'altiProfil');      
        $this->Altisource = $localConfig->getValue('altisource', 'altiProfil');
        return $localConfig;
    }

    /**
     * Get alti from one point based on database
    **/
    public function getAlti($lon, $lat){       
        $queryalti = $this->queryAlti($lon, $lat);        
        return $queryalti;        
    }

    /**
     * Alti SQL Query 
    **/
    private function queryAlti($lon, $lat) {
        $this->getModuleConfig();
        $sql = sprintf("
            SELECT ST_Value(
                %s.rast, 
                ST_Transform(ST_SetSRID(ST_MakePoint(%f,%f),4326),%s)
            ) as z
            FROM %s
            WHERE ST_Intersects(
                %s.rast, 
                ST_Transform(ST_SetSRID(ST_MakePoint(%f,%f),4326),%s)
                
        )", $this->AltiProfileTable, 
            $lon, $lat, $this->Srid, 
            $this->AltiProfileTable, 
            $this->AltiProfileTable,
            $lon, $lat, $this->Srid
        );
        $cnx = jDb::getConnection( 'altiProfil' );
        $qResult = $cnx->query( $sql );
        $result = array("elevations"=>[$qResult->fetch(PDO::FETCH_ASSOC)]);       
        return json_encode($result);
    }

    /**
     * Get alti from database based on one point  
    **/
    public function getProfil($p1Lon, $p1Lat, $p2Lon, $p2Lat){        
        $getProfil =$this->queryProfil($p1Lon, $p1Lat, $p2Lon, $p2Lat);        
        return $getProfil;
    }

    /**
     * SQL Query Profil from database
    **/
    protected function queryProfil($p1Lon, $p1Lat, $p2Lon, $p2Lat){
        $this->getModuleConfig();
        //ref: https://blog.mathieu-leplatre.info/drape-lines-on-a-dem-with-postgis.html
        $sql = sprintf("
            WITH 
                line AS(
                    -- From an arbitrary line
                    SELECT 
                        ST_MakeLine(
                            ST_Transform(ST_SetSRID(ST_MakePoint(%f,%f),4326),%s),
                            ST_Transform(ST_SetSRID(ST_MakePoint(%f, %f),4326),%s)
                        )
                    AS geom),
                linemesure AS(
                -- Add a mesure dimension to extract steps
                SELECT 
                    ST_AddMeasure(line.geom, 0, ST_Length(line.geom)) as linem,
                    generate_series(
                        0, 
                        ST_Length(line.geom)::int, 
                        CASE 
                            WHEN ST_Length(line.geom)::int < 1000 THEN 5
                            ELSE 20
                        END
                    ) as i,
                    CASE 
                        WHEN ST_Length(line.geom)::int < 1000 THEN 5
                        ELSE 20
                    END as resolution
                FROM line),
                points2d AS (
                    SELECT ST_GeometryN(ST_LocateAlong(linem, i), 1) AS geom, resolution FROM linemesure
                ),
                cells AS (
                -- Get DEM elevation for each
                    SELECT p.geom AS geom, ST_Value(foncier.reunion_mnt.rast, 1, p.geom) AS val, resolution
                    FROM %s, points2d p
                    WHERE ST_Intersects(%s.rast, p.geom)
                ),
                -- Instantiate 3D points
                points3d AS (
                    SELECT ST_SetSRID(
                                ST_MakePoint(ST_X(geom), ST_Y(geom), val), 
                                %s
                            ) AS geom, resolution FROM cells
                ),
                line3D AS(
                    SELECT ST_MakeLine(geom)as geom, MAx(resolution) as resolution FROM points3d
                ),
                xz AS(
                    SELECT (ST_DumpPoints(geom)).geom AS geom,
                    ST_StartPoint(geom) AS origin, resolution
                    FROM line3D
                )
            -- Build 3D line from 3D points
            SELECT ST_distance(origin, geom) AS x, ST_Z(geom) as y, ST_X(geom) as lon, ST_Y(geom) as lat, resolution FROM xz",            
            $p1Lon, $p1Lat, $this->Srid,
            $p2Lon,$p2Lat, $this->Srid, 
            $this->AltiProfileTable, 
            $this->AltiProfileTable,
            $this->Srid
        );
        $cnx = jDb::getConnection('altiProfil');
        $qResult = $cnx->query($sql);
        $x = array();
        $y = array();
        $customdata = array();
        $resolution = "";
        while($row=$qResult->fetch())  {
            $x[] = $row->x;
            $y[] = $row->y;
            $customdata[] = [$row->lon, $row->lat];
            $resolution = $row->resolution;
        }
        //slope
        $sql = sprintf(" 
            WITH 
                line AS(
                    -- From an arbitrary line
                    SELECT 
                        ST_MakeLine(
                            ST_Transform(ST_SetSRID(ST_MakePoint(%f,%f),4326),%s),
                            ST_Transform(ST_SetSRID(ST_MakePoint(%f, %f),4326),%s)
                        )
                    AS geom
                ), RasterCells AS (
                    -- Get DEM elevation for each
                    SELECT ST_Clip(%s.rast, line.geom, -9999, TRUE) as rast
                    FROM %s, line
                    WHERE ST_Intersects(%s.rast, line.geom)
                ), rasterSlopStat AS (
                    Select (ST_SummaryStatsAgg(ST_Slope(rast, 1, '32BF', 'DEGREES', 1.0), 1, TRUE, 1)).*
                    FROM RasterCells
                )
                SELECT  (rasterSlopStat).count,
                        Round((rasterSlopStat).min::numeric, 2) as min_slope, 
                        Round((rasterSlopStat).max::numeric, 2) as max_slope,
                        Round((rasterSlopStat).mean::numeric, 2) as mean_slope
                        FROM rasterSlopStat
        
            ",
            $p1Lon, $p1Lat, $this->Srid,
            $p2Lon,$p2Lat, $this->Srid, 
            $this->AltiProfileTable,             
            $this->AltiProfileTable,
            $this->AltiProfileTable
        );
        $cnx = jDb::getConnection('altiProfil');
        $qResult = $cnx->query($sql); 
        $slope = json_encode(
                    $qResult->fetch(PDO::FETCH_ASSOC)
                );  
        $data = [ [
            "x" => $x,
            "y" => $y,  
            "customdata" => $customdata,
            "srid" => $this->Srid,
            "resolution" => $resolution,
            "altisource" => $this->Altisource,
            "slope_degrees" => $slope,
            "source" => 'DB'
         ] ]; 
         
        return json_encode($data);
    }
}
?>