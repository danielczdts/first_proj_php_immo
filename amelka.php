<?php

require('C:/xampp/htdocs/amelia2/wp-blog-header.php');

require('config.php');

$servername = $config['servername'];
$username = $config['username'];
$password = $config['password'];
$database = $config['database'];

// Establish connection to the database
$conn = new mysqli($servername, $username, $password, $database);

function get_wp_ps_import_ids() {
    global $conn;

    try {
        $wp_ps_import_ids = array();
        $result = $conn->query("SELECT id,id_ps FROM wp_ps_import");
        if ($result === false) {
            throw new Exception("Error retrieving data from wp_ps_import table: " . $conn->error);
        }
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $wp_ps_import_ids[] = $row['id_ps'];
            }
        }
        return $wp_ps_import_ids;
    } catch (Exception $e) {
        echo "An error occurred in get_wp_ps_import_id: " . $e->getMessage();
    }
}

function get_wp_posts_Max_ID() {
    global $conn;

    try {
        $max_result = $conn->query("SELECT MAX(id) AS max_id FROM wp_posts");
        if ($max_result === false) {
            throw new Exception("Error retrieving data from wp_posts table: " . $conn->error);
        }
        $row = $max_result->fetch_assoc();
        $wp_posts_Max_ID = $row['max_id'];

        return $wp_posts_Max_ID;
    } catch (Exception $e) {
        echo "An error occurred in get_wp_posts_Max_ID: " . $e->getMessage();
    }
}

function insert_wp($json_id, $item) {
    global $conn;

    try {
        // Get the maximum ID from wp_posts and increment by 1 for new ID
        $new_id = get_wp_posts_Max_ID() + 1;
        $sql = "INSERT INTO wp_ps_import (id, id_ps) VALUES ($new_id, $json_id)";
        if ($conn->query($sql) === TRUE) {
            echo "New record inserted successfully: $json_id";
            try {
                create_custom_property($new_id, $item);
            } catch (Exception $e) {
                echo "Error creating custom property: " . $e->getMessage() . "\n";
            }
        } else {
            throw new Exception("Error inserting record: " . $conn->error);
        }
    } catch (Exception $e) {
        echo "An error occurred in insert_wp: " . $e->getMessage();
    }
}

function get_match($db_ids, $json_id) {
    foreach ($db_ids as $db_id) {
        if ($db_id == $json_id) {
            return $db_id;
        }
    }
    return null;
}

function get_id_from_wp_ps_import($id_ps) {
    global $conn;

    $result = $conn->query("SELECT id FROM wp_ps_import WHERE id_ps = $id_ps");
    if ($result === false) {
        throw new Exception("Error retrieving data from wp_ps_import table: " . $conn->error);
    }
    $row = $result->fetch_assoc();
    return $row['id'];
}

function update_wp_content($dataJsons) {
    global $conn;

    try {
        if ($conn->connect_error) {
            throw new Exception("Connection to the DB failed: " . $conn->connect_error);
        }
        echo "Connection to the DB was successful" . "<br>";

        // Retrieve IDs from the wp_ps_import table
        $db_ids = get_wp_ps_import_ids();

        // Iterate through the JSON data and perform UPDATEs, INSERTs, and DELETEs
        foreach ($dataJsons['data'] as $item) {
            $json_id = $item['id'];

            if (in_array($json_id, $db_ids)) {
                // If the object already exists in the database, update it
                $db_id = get_match($db_ids, $json_id);
                echo("UPDATE");
                update_custom_property($db_id, $item);
            } else {
                echo("INSERT ID: " . $json_id . "<br>");
                insert_wp($json_id, $item);
            }
        }

        // Iterate through the IDs present in the database and check if they still exist in the JSON file
        foreach ($db_ids as $db_id) {
            if (!in_array($db_id, array_column($dataJsons['data'], 'id'))) {
                $del_id = get_id_from_wp_ps_import($db_id);
                echo("!!!!!!!!!!! DELETE !!!!!!!!!!!!!" . "<br>");
                $sql = "DELETE FROM wp_posts WHERE post_parent = $del_id OR ID = $del_id";
                if ($conn->query($sql) === TRUE) {
                    echo "Record deleted successfully: $del_id" . "<br>";
                }
            }
        }

        $conn->close();
    } catch (Exception $e) {
        echo "An error occurred: " . $e->getMessage();
    }
}

function upload_image($id_ps, $image_url) {
    if (empty($image_url)) {
        echo "Error: Image URL is empty for post ID: $id_ps<br>";
        return false; // Skip empty URLs
    }

    echo("!!!!!!!!!!!!!!upload_image id_ps: " . $id_ps . " image_url: " . $image_url . "<br>");

    $upload_dir = wp_upload_dir();
    $image_data = file_get_contents($image_url);
    $filename = basename($image_url);
    if (wp_mkdir_p($upload_dir['path'])) {
        $file = $upload_dir['path'] . '/' . $filename;
    } else {
        $file = $upload_dir['basedir'] . '/' . $filename;
    }
    file_put_contents($file, $image_data);

    $wp_filetype = wp_check_filetype($filename, null);
    $attachment = array(
        'post_mime_type' => $wp_filetype['type'],
        'post_title' => sanitize_file_name($filename),
        'post_content' => '',
        'post_status' => 'inherit',
        'post_parent' => $id_ps
    );
    $attach_id = wp_insert_attachment($attachment, $file);

    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($attach_id, $file);
    wp_update_attachment_metadata($attach_id, $attach_data);

    return $attach_id;
}

function create_custom_property($id_ps, $item) {
    if (isset($item['title'], $item['content'], $item['images'])) {
        $title = $item['title'];
        $content = $item['content'];
        $images = $item['images'];
    } else {
        echo 'Error: Missing required data.';
        return false;
    }

    // Create a new WordPress post
    $post_data = array(
        'post_author' => 1,
        'post_content' => $content,
        'post_title' => $title,
        'post_status' => 'publish',
        'post_type' => 'property',
    );

    // Insert the post and get the post ID
    $prop_id = wp_insert_post($post_data);
    if ($prop_id) {
        // Upload and set the featured image
        $first_image_uploaded = false;
        foreach ($images as $image) {
            foreach ($image as $url) {
                if (is_string($url) && preg_match('/\.jpg$/', $url)) {
                    $image_id = upload_image($prop_id, $url);
                    if ($image_id) {
                        if (!$first_image_uploaded) {
                            set_post_thumbnail($prop_id, $image_id);
                            $first_image_uploaded = true;
                        }
                    } else {
                        echo "Error uploading image for post ID: $prop_id\n";
                    }
                }
            }
        }

        // Update post metadata
        $meta_map = array(
            'price' => 'fave_property_price',
            'price-label' => 'fave_property_price_postfix',
        );
        foreach ($meta_map as $data_key => $meta_key) {
            if (isset($item[$data_key])) {
                update_post_meta($prop_id, $meta_key, $item[$data_key]);
            }
        }

        // Update taxonomy terms
        $taxonomy_map = array(
            'types' => 'property_type',
            'statuses' => 'property_status',
            'labels' => 'property_label',
            'country' => 'property_country',
            'area' => 'property_area',
            'city' => 'property_city',
            'state' => 'property_state',
            'features' => 'property_feature',
        );
        foreach ($taxonomy_map as $data_key => $taxonomy) {
            if (isset($item[$data_key])) {
                wp_set_object_terms($prop_id, $item[$data_key], $taxonomy);
            }
        }

        return $prop_id;
    } else {
        return false;
    }
}

function getIDFromDB($id_ps) {
    global $conn;
    $result = $conn->query("SELECT id FROM wp_ps_import WHERE id_ps = $id_ps");
    $row = $result->fetch_assoc();
    return $row['id'];
}

function update_custom_property($post_id, $item) {
    global $conn;
    $title = isset($item['title']) ? $item['title'] : ' ';
    $content = isset($item['content']) ? $item['content'] : ' ';
    $images = $item['images'];
    $id_ps = getIDFromDB($post_id);
    echo("Update Id: " . $id_ps . "<br>");

    $sql = "UPDATE wp_posts SET post_title = '$title', post_content = '$content' WHERE ID = $id_ps";
    if ($conn->query($sql) === true) {
        echo "SQL is working";
    } else {
        echo "SQL is NOT working";
    }

    $taxonomy_map = array(
        'types' => 'property_type',
        'statuses' => 'property_status',
        'labels' => 'property_label',
        'country' => 'property_country',
        'area' => 'property_area',
        'city' => 'property_city',
        'state' => 'property_state',
        'features' => 'property_feature',
    );

    foreach ($taxonomy_map as $data_key => $taxonomy) {
        if (isset($item[$data_key])) {
            wp_set_object_terms($post_id, $item[$data_key], $taxonomy);
        }
    }

    // Get the post parent ID from wp_ps_import table
    $post_parent_id = get_id_from_wp_ps_import($post_id);

    // Get the list of existing images associated with the current post from wp_posts table
    $existing_images = array();
    $filenames = $conn->query("SELECT ID, guid, post_title FROM wp_posts WHERE post_parent = $post_parent_id AND post_type = 'attachment'");
    while ($row = $filenames->fetch_assoc()) {
        $existing_images[] = $row['post_title']; // Store the post titles of existing images
    }

    // Track existing images by filename to check if they need to be removed or updated
    $existing_image_files = array_map('basename', $existing_images);

   // Upload new images or update existing ones
    foreach ($images as $image) {
        foreach ($image as $url) {
            if (empty($url)) {
                echo "Error: Encountered empty image URL, skipping.<br>";
                continue;
            }
            $image_name = basename($url);
            if (!in_array($image_name, $existing_image_files)) {
                echo("Uploading new image: $image_name <br>");
                $image_id = upload_image($post_parent_id, $url);
                if ($image_id && !$first_image_uploaded) {
                    set_post_thumbnail($post_parent_id, $image_id);
                    $first_image_uploaded = true;
                }
            } else {
                echo("Image already exists: $image_name <br>");
            }
        }
    }


    // Delete images that are in the database but not in the JSON file
    $json_image_files = array();
    foreach ($images as $image) {
        foreach ($image as $url) {
            if (!empty($url)) {
                $json_image_files[] = basename($url);
            }
        }
    }

    $images_to_delete = array_diff($existing_image_files, $json_image_files);
    foreach ($images_to_delete as $image_to_delete) {
        echo("Deleting image: $image_to_delete <br>");
        $delete_sql = "DELETE FROM wp_posts WHERE post_parent = $post_parent_id AND post_title = '$image_to_delete'";
        if ($conn->query($delete_sql) === TRUE) {
            echo "Image deleted successfully: $image_to_delete <br>";
        } else {
            echo "Error deleting image: " . $conn->error . "<br>";
        }
    }
}

// Read JSON data
$json_data = file_get_contents('data-mock4.json');
$data = json_decode($json_data, true);

update_wp_content($data);   
?>