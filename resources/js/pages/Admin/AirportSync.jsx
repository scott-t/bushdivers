import {
  Alert,
  AlertDescription,
  AlertIcon,
  Badge,
  Button,
  Card,
  CardBody,
  Checkbox,
  Collapse,
  Flex,
  FormControl,
  FormLabel,
  Grid,
  GridItem,
  Heading,
  Input,
  Progress,
  Radio,
  RadioGroup,
  Stack,
  Stat,
  StatLabel,
  StatNumber,
  Table,
  Tbody,
  Td,
  Text,
  Th,
  Thead,
  Tr,
  useToast,
} from '@chakra-ui/react'
import axios from 'axios'
import React, { useEffect, useMemo, useState } from 'react'

import AdminLayout from '../../components/layout/AdminLayout'
import { SimType, SimTypeNames } from '../../helpers/simtype.helpers.js'

const typeColor = {
  possible_swap: 'orange',
  possible_rename: 'blue',
  promote_thirdparty: 'green',
  ambiguous_proximity: 'red',
}

const POLL_INTERVAL_MS = 2000

const decisionsByType = {
  possible_swap: [
    { value: 'rename', label: 'Rename existing' },
    { value: 'new', label: 'Add as new' },
    { value: 'ignore', label: 'Ignore' },
  ],
  possible_rename: [
    { value: 'rename', label: 'Rename existing' },
    { value: 'new', label: 'Add as new' },
    { value: 'ignore', label: 'Ignore' },
  ],
  promote_thirdparty: [
    { value: 'promote', label: 'Promote to base airport' },
    { value: 'rename', label: 'Keep as third-party' },
    { value: 'new', label: 'Add as new' },
    { value: 'ignore', label: 'Ignore' },
  ],
  ambiguous_proximity: [
    { value: 'new', label: 'Add as new' },
    { value: 'ignore', label: 'Ignore' },
  ],
}

const AirportSync = ({ sessionId: initialSessionId = null }) => {
  const toast = useToast()
  const [file, setFile] = useState(null)
  const [simType, setSimType] = useState(SimType.FS20)
  const [sessionId, setSessionId] = useState(initialSessionId)
  const [session, setSession] = useState(null)
  const [uploading, setUploading] = useState(false)
  const [showDeactivations, setShowDeactivations] = useState(false)
  const [includeDeactivations, setIncludeDeactivations] = useState(false)

  const status = session?.status ?? null
  const reviewItems = session?.results?.review_items ?? []
  const unresolved = reviewItems.filter((item) => !item.admin_decision).length

  useEffect(() => {
    if (!sessionId) {
      return
    }

    const shouldPoll = !status || ['queued', 'processing'].includes(status)

    if (!shouldPoll) {
      return
    }

    const poll = async () => {
      try {
        const { data } = await axios.get(
          `/admin/airports/sync/${sessionId}/status`
        )
        setSession(data)
      } catch {
        toast({
          status: 'error',
          title: 'Failed to fetch sync status',
        })
      }
    }

    poll()

    const interval = setInterval(poll, POLL_INTERVAL_MS)

    return () => clearInterval(interval)
  }, [sessionId, status, toast])

  const progress = useMemo(() => {
    const current = session?.progress?.current ?? 0
    const total = session?.progress?.total ?? 0

    if (!total) {
      return 0
    }

    return Math.round((current / total) * 100)
  }, [session])

  const upload = async () => {
    if (!file) {
      toast({
        status: 'warning',
        title: 'Select a CSV file first',
      })
      return
    }

    setUploading(true)

    const formData = new FormData()
    formData.append('file', file)
    formData.append('sim_type', simType)

    try {
      const { data } = await axios.post('/admin/airports/sync', formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      })

      setSessionId(data.sessionId)
      setSession({ status: 'queued' })
    } catch (error) {
      toast({
        status: 'error',
        title: 'Upload failed',
        description: error?.response?.data?.message,
      })
    } finally {
      setUploading(false)
    }
  }

  const setDecision = async (item, decision) => {
    if (!sessionId) {
      return
    }

    try {
      const { data } = await axios.post(
        `/admin/airports/sync/${sessionId}/resolve`,
        { itemId: item.id, decision }
      )
      setSession(data)
    } catch {
      toast({
        status: 'error',
        title: 'Failed to save review decision',
      })
    }
  }

  const execute = async () => {
    if (!sessionId) {
      return
    }

    try {
      await axios.post(`/admin/airports/sync/${sessionId}/execute`, {
        include_deactivations: includeDeactivations,
      })

      toast({ status: 'success', title: 'Execution queued' })
      setSession((prev) => ({ ...(prev ?? {}), status: 'queued' }))
    } catch (error) {
      toast({
        status: 'error',
        title: 'Execution failed',
        description: error?.response?.data?.message,
      })
    }
  }

  const reset = () => {
    setFile(null)
    setSimType(SimType.FS20)
    setSessionId(null)
    setSession(null)
    setShowDeactivations(false)
    setIncludeDeactivations(false)
  }

  return (
    <AdminLayout heading="Airport Sync" subHeading="LittleNavMap import">
      {!sessionId && (
        <Card>
          <CardBody>
            <Stack spacing={4}>
              <FormControl isRequired>
                <FormLabel>CSV File</FormLabel>
                <Input
                  type="file"
                  accept=".csv,.txt"
                  onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                />
              </FormControl>

              <FormControl isRequired>
                <FormLabel>Sim Type</FormLabel>
                <RadioGroup value={simType} onChange={setSimType}>
                  <Stack direction="row" spacing={6}>
                    <Radio value={SimType.FS20}>
                      {SimTypeNames[SimType.FS20]}
                    </Radio>
                    <Radio value={SimType.FS24}>
                      {SimTypeNames[SimType.FS24]}
                    </Radio>
                  </Stack>
                </RadioGroup>
              </FormControl>

              <Flex justify="flex-end">
                <Button onClick={upload} isLoading={uploading}>
                  Upload
                </Button>
              </Flex>
            </Stack>
          </CardBody>
        </Card>
      )}

      {['queued', 'processing'].includes(status) && (
        <Card>
          <CardBody>
            <Stack spacing={4}>
              <Text>Analysing airports...</Text>
              <Progress value={progress} hasStripe isAnimated />
              <Text fontSize="sm">
                {session?.progress?.current ?? 0} /{' '}
                {session?.progress?.total ?? 0}
              </Text>
            </Stack>
          </CardBody>
        </Card>
      )}

      {status === 'complete' && (
        <Stack spacing={6}>
          <Grid templateColumns="repeat(4, minmax(0, 1fr))" gap={4}>
            <GridItem>
              <Stat>
                <StatLabel>Auto Updates</StatLabel>
                <StatNumber>
                  {session?.results?.summary?.auto_updates ?? 0}
                </StatNumber>
              </Stat>
            </GridItem>
            <GridItem>
              <Stat>
                <StatLabel>New Airports</StatLabel>
                <StatNumber>
                  {session?.results?.summary?.new_airports ?? 0}
                </StatNumber>
              </Stat>
            </GridItem>
            <GridItem>
              <Stat>
                <StatLabel>Review Items</StatLabel>
                <StatNumber>
                  {session?.results?.summary?.review_items ?? 0}
                </StatNumber>
              </Stat>
            </GridItem>
            <GridItem>
              <Stat>
                <StatLabel>Deactivations</StatLabel>
                <StatNumber>
                  {session?.results?.summary?.deactivations ?? 0}
                </StatNumber>
              </Stat>
            </GridItem>
          </Grid>

          <Card>
            <CardBody>
              <Stack spacing={4}>
                <Heading size="sm">Review items</Heading>
                <Text fontSize="sm">Unresolved: {unresolved}</Text>

                <Table size="sm">
                  <Thead>
                    <Tr>
                      <Th>Type</Th>
                      <Th>Confidence</Th>
                      <Th>Incoming</Th>
                      <Th>Candidate</Th>
                      <Th>Action</Th>
                    </Tr>
                  </Thead>
                  <Tbody>
                    {reviewItems.map((item) => (
                      <Tr key={item.id}>
                        <Td>
                          <Badge colorScheme={typeColor[item.type] ?? 'gray'}>
                            {item.type}
                          </Badge>
                        </Td>
                        <Td>
                          <Badge>{item.confidence}</Badge>
                        </Td>
                        <Td>
                          <Text>{item.incoming.identifier}</Text>
                          <Text fontSize="xs">{item.incoming.name}</Text>
                          <Text fontSize="xs">
                            {item.incoming.lat}, {item.incoming.lon}
                          </Text>
                        </Td>
                        <Td>
                          <Text>{item.candidate?.identifier}</Text>
                          <Text fontSize="xs">{item.candidate?.name}</Text>
                          <Text fontSize="xs">{item.distance_nm} nm</Text>
                        </Td>
                        <Td>
                          <RadioGroup
                            value={item.admin_decision ?? ''}
                            onChange={(value) => setDecision(item, value)}
                          >
                            <Stack>
                              {(decisionsByType[item.type] ?? []).map(
                                (decision) => (
                                  <Radio
                                    key={decision.value}
                                    value={decision.value}
                                  >
                                    {decision.label}
                                  </Radio>
                                )
                              )}
                            </Stack>
                          </RadioGroup>
                        </Td>
                      </Tr>
                    ))}
                  </Tbody>
                </Table>
              </Stack>
            </CardBody>
          </Card>

          <Card>
            <CardBody>
              <Stack spacing={3}>
                <Button
                  variant="link"
                  alignSelf="flex-start"
                  onClick={() => setShowDeactivations((current) => !current)}
                >
                  {showDeactivations
                    ? 'Hide deactivations'
                    : 'Show deactivations'}
                </Button>

                <Collapse in={showDeactivations} animateOpacity>
                  <Stack spacing={3}>
                    <Alert status="warning">
                      <AlertIcon />
                      <AlertDescription>
                        Deactivations remove this sim type from matched
                        airports.
                      </AlertDescription>
                    </Alert>

                    <Checkbox
                      isChecked={includeDeactivations}
                      onChange={(event) =>
                        setIncludeDeactivations(event.target.checked)
                      }
                    >
                      Include deactivations
                    </Checkbox>

                    <Stack spacing={1}>
                      {(session?.results?.deactivations ?? []).map(
                        (airport) => (
                          <Text key={airport.id} fontSize="sm">
                            {airport.identifier} - {airport.name}
                          </Text>
                        )
                      )}
                    </Stack>
                  </Stack>
                </Collapse>
              </Stack>
            </CardBody>
          </Card>

          <Flex justify="flex-end">
            <Button onClick={execute} isDisabled={unresolved > 0}>
              Apply Changes
            </Button>
          </Flex>
        </Stack>
      )}

      {status === 'executed' && (
        <Card>
          <CardBody>
            <Stack spacing={4}>
              <Heading size="sm">Execution summary</Heading>
              {Object.entries(session?.execution_summary ?? {}).map(
                ([key, value]) => (
                  <Text key={key}>
                    {key}: {value}
                  </Text>
                )
              )}
              <Button alignSelf="flex-start" onClick={reset}>
                Start new sync
              </Button>
            </Stack>
          </CardBody>
        </Card>
      )}

      {status === 'failed' && (
        <Alert status="error">
          <AlertIcon />
          <AlertDescription>
            Sync failed: {session?.error ?? 'Unknown error'}
          </AlertDescription>
        </Alert>
      )}
    </AdminLayout>
  )
}

export default AirportSync
