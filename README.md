
# Otrs (github.com/OTRS) To Elastic/Kibana export

## Use Cases: Key Performance Indicator (KPI) Tracker 

Tracked Events: "EmailCustomer", "SendAnswer", "FollowUp"

1. Standard Evens History: EmailCustomer -> SendAnswer -> FollowUp -> SendAnswer -> ...

2. also possible Evens History: SendAnswer -> SendAnswer -> FollowUp -> FollowUp -> ...

## Install
 
```

# run export with default config yaml
run export.php

# run export with optional config yaml 
run export.php -f[config.yml]

# start elastic and grafana:
/Downloads/elasticsearch-5.4.1$ bin/elasticsearch
/Downloads/grafana-4.3.2$ bin/grafana-server

# make ssh tunel to mysql like
ssh -v -L 3366:localhost:3306 use@server

# import kibana.json to grafana
```

## OTRS Ticket Domain Model

https://github.com/OTRS/otrs/blob/master/development/diagrams/Database/OTRSDatabaseDiagram.png

=> To differentiate "FollowUp" history events between interen and extern User (wich we want to track) filter by time: 

```
SELECT ticket_history.id,
       ticket_history.create_time,
       ticket_history_type.name AS history_type_name,
       ticket_state.name        AS ticket_state_name,
       users.login,
       article_type.name        AS article_type_name,
       article_sender_type.name AS article_sender_type_name
FROM   ticket
       INNER JOIN ticket_history
               ON ticket.id = ticket_history.ticket_id
       INNER JOIN ticket_history_type
               ON ticket_history.history_type_id = ticket_history_type.id
       INNER JOIN ticket_state
               ON ticket_history.state_id = ticket_state.id
       INNER JOIN users
               ON ticket_history.create_by = users.id
       LEFT JOIN article
              ON ticket_history.article_id = article.id
       LEFT JOIN article_type
              ON article.article_type_id = article_type.id
       LEFT JOIN article_sender_type
              ON article.article_sender_type_id = article_sender_type.id
WHERE  ticket_history.ticket_id = 330717
       AND article_type.name = 'email-external'
       AND ticket_history_type.name IN ( 'EmailCustomer', 'SendAnswer', 'FollowUp' )
       AND ticket_history.change_time NOT IN
           (SELECT change_time
            FROM   ticket_history
            WHERE  ticket_history.ticket_id = 330717
                   AND change_by = 1
                   AND ( ticket_history.name LIKE '%customer response required%'
                          OR ticket_history.name LIKE '%internal response required%' ))
```

