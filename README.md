# SyncProcessor

recieves s3_path from File Upload Service

triggers a lambda function to insert the object from s3_path into database,
copy the object to folder(Completed),
delete the object to folder(Pending)